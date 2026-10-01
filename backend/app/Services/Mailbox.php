<?php

namespace App\Services;

use App\Models\ConnectedAccount;
use App\Models\Lead;
use App\Notifications\AppNotification;
use App\Security\OAuthClient;
use App\Support\Tenant;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * A person's own Gmail / Outlook mailbox and calendar: emails to and from
 * leads land on the timeline and in the inbox, emails to leads are sent from
 * the person's real address, their busy times block booking slots, and
 * booked meetings are added to their calendar.
 */
class Mailbox
{
    public const SCOPES = [
        'google' => ['openid', 'email', 'profile', 'https://www.googleapis.com/auth/gmail.readonly', 'https://www.googleapis.com/auth/gmail.send', 'https://www.googleapis.com/auth/calendar.events', 'https://www.googleapis.com/auth/calendar.freebusy'],
        'microsoft' => ['openid', 'email', 'profile', 'offline_access', 'User.Read', 'Mail.Read', 'Mail.Send', 'Calendars.ReadWrite'],
    ];

    public function __construct(private OAuthClient $oauth, private ActivityRecorder $activities) {}

    public static function configured(string $provider): bool
    {
        return filled(config("services.{$provider}.client_id")) && filled(config("services.{$provider}.client_secret"));
    }

    public static function issuer(string $provider): string
    {
        return OAuthClient::issuer($provider, ['tenant' => config('services.microsoft.tenant')]);
    }

    // ------------------------------------------------------------ tokens

    private function client(ConnectedAccount $account): PendingRequest
    {
        if ($account->expires_at && $account->expires_at->lessThan(now()->addMinutes(2)) && $account->refresh_token) {
            $endpoints = $this->oauth->discover(self::issuer($account->provider));
            $t = $this->oauth->refresh($endpoints['token_endpoint'], config("services.{$account->provider}.client_id"), config("services.{$account->provider}.client_secret"), $account->refresh_token);
            $account->forceFill([
                'access_token' => $t['access_token'],
                'refresh_token' => $t['refresh_token'] ?? $account->refresh_token,
                'expires_at' => now()->addSeconds((int) ($t['expires_in'] ?? 3600)),
            ])->save();
        }

        return Http::timeout(20)->withToken($account->access_token)->acceptJson()->throw();
    }

    // ------------------------------------------------------------ mail

    /** Pull new emails exchanged with leads since the last sync. Returns how many landed on timelines. */
    public function syncMail(ConnectedAccount $account): int
    {
        $since = ($account->last_synced_at ?? now()->subDays(3))->copy()->subMinutes(5);
        $messages = $account->provider === 'google' ? $this->gmailSince($account, $since) : $this->graphSince($account, $since);
        $added = 0;

        Tenant::run($account->organization_id, function () use ($account, $messages, &$added) {
            foreach ($messages as $m) {
                $outbound = Str::lower($m['from']) === Str::lower($account->email);
                $counterparts = $outbound ? $m['to'] : [$m['from']];
                $lead = Lead::whereIn(DB::raw('lower(email)'), array_map('strtolower', $counterparts))->latest('id')->first();
                if (! $lead || ! $this->remember($account, $m['id'])) {
                    continue;
                }
                $this->activities->record($lead, 'email', $m['subject'] ?: '(no subject)', [
                    'user_id' => $account->user_id,
                    'description' => mb_substr($m['body'], 0, 10000),
                    'direction' => $outbound ? 'outbound' : 'inbound',
                    'occurred_at' => $m['date'],
                    'meta' => ['mailbox' => $account->provider, 'message_id' => $m['id']],
                ]);
                $added++;
                if (! $outbound) {
                    $lead->forceFill(['last_contacted_at' => $m['date']])->saveQuietly();
                    $lead->owner?->notify(new AppNotification("{$lead->full_name} emailed you", $m['subject'] ?: Str::limit($m['body'], 140), "/inbox?lead={$lead->id}", 'inbound_message'));
                }
            }
        });
        $account->forceFill(['last_synced_at' => now(), 'last_error' => null])->save();

        return $added;
    }

    /** Send from the person's own mailbox; returns the provider message id when there is one. */
    public function send(ConnectedAccount $account, string $to, string $toName, string $subject, string $text): ?string
    {
        if ($account->provider === 'google') {
            $raw = implode("\r\n", [
                'From: '.$this->address($account->email, $account->user?->name),
                'To: '.$this->address($to, $toName),
                'Subject: =?UTF-8?B?'.base64_encode($subject).'?=',
                'MIME-Version: 1.0',
                'Content-Type: text/plain; charset=UTF-8',
                'Content-Transfer-Encoding: base64',
                '',
                chunk_split(base64_encode($text)),
            ]);
            $id = $this->client($account)->post('https://gmail.googleapis.com/gmail/v1/users/me/messages/send', ['raw' => rtrim(strtr(base64_encode($raw), '+/', '-_'), '=')])->json('id');
        } else {
            $this->client($account)->post('https://graph.microsoft.com/v1.0/me/sendMail', [
                'message' => ['subject' => $subject, 'body' => ['contentType' => 'Text', 'content' => $text], 'toRecipients' => [['emailAddress' => ['address' => $to, 'name' => $toName]]]],
                'saveToSentItems' => true,
            ]);
            $id = null; // Graph doesn't return one; the sync dedupes by the sent item's own id later
        }
        if ($id) {
            $this->remember($account, $id);
        }

        return $id;
    }

    private function remember(ConnectedAccount $account, string $messageId): bool
    {
        return DB::table('synced_messages')->insertOrIgnore(['connected_account_id' => $account->id, 'message_id' => mb_substr($messageId, 0, 250), 'created_at' => now()]) > 0;
    }

    private function address(string $email, ?string $name): string
    {
        return $name ? '=?UTF-8?B?'.base64_encode($name)."?= <{$email}>" : $email;
    }

    /** @return list<array{id: string, from: string, to: list<string>, subject: string, body: string, date: Carbon}> */
    private function gmailSince(ConnectedAccount $account, Carbon $since): array
    {
        $http = $this->client($account);
        $ids = $http->get('https://gmail.googleapis.com/gmail/v1/users/me/messages', ['q' => 'after:'.$since->timestamp.' -in:chats -in:spam', 'maxResults' => 100])->json('messages') ?? [];

        return collect($ids)->map(function ($ref) use ($http) {
            $m = $http->get("https://gmail.googleapis.com/gmail/v1/users/me/messages/{$ref['id']}", ['format' => 'full'])->json();
            $headers = collect($m['payload']['headers'] ?? [])->mapWithKeys(fn ($h) => [strtolower($h['name']) => $h['value']]);

            return [
                'id' => $m['id'],
                'from' => $this->emails($headers['from'] ?? '')[0] ?? '',
                'to' => $this->emails(($headers['to'] ?? '').','.($headers['cc'] ?? '')),
                'subject' => (string) ($headers['subject'] ?? ''),
                'body' => trim($this->gmailText($m['payload'] ?? []) ?: html_entity_decode($m['snippet'] ?? '')),
                'date' => isset($m['internalDate']) ? Carbon::createFromTimestampMs((int) $m['internalDate']) : now(),
            ];
        })->all();
    }

    private function gmailText(array $part): string
    {
        if (($part['mimeType'] ?? '') === 'text/plain' && isset($part['body']['data'])) {
            return (string) base64_decode(strtr($part['body']['data'], '-_', '+/'));
        }
        foreach ($part['parts'] ?? [] as $child) {
            if ($text = $this->gmailText($child)) {
                return $text;
            }
        }

        return '';
    }

    private function graphSince(ConnectedAccount $account, Carbon $since): array
    {
        $rows = $this->client($account)->withHeaders(['Prefer' => 'outlook.body-content-type="text"'])->get('https://graph.microsoft.com/v1.0/me/messages', [
            '$filter' => 'lastModifiedDateTime ge '.$since->utc()->format('Y-m-d\TH:i:s\Z'),
            '$top' => 100,
            '$select' => 'id,subject,from,toRecipients,ccRecipients,body,bodyPreview,sentDateTime,receivedDateTime,isDraft',
        ])->json('value') ?? [];

        return collect($rows)->reject(fn ($m) => $m['isDraft'] ?? false)->map(fn ($m) => [
            'id' => $m['id'],
            'from' => strtolower($m['from']['emailAddress']['address'] ?? ''),
            'to' => collect([...($m['toRecipients'] ?? []), ...($m['ccRecipients'] ?? [])])->map(fn ($r) => strtolower($r['emailAddress']['address'] ?? ''))->filter()->values()->all(),
            'subject' => (string) ($m['subject'] ?? ''),
            'body' => trim((string) ($m['body']['content'] ?? $m['bodyPreview'] ?? '')),
            'date' => Carbon::parse($m['receivedDateTime'] ?? $m['sentDateTime'] ?? now()),
        ])->values()->all();
    }

    /** @return list<string> */
    private function emails(string $header): array
    {
        preg_match_all('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', $header, $m);

        return array_values(array_unique(array_map('strtolower', $m[0])));
    }

    // ------------------------------------------------------------ calendar

    /** @return list<array{0: Carbon, 1: Carbon}> busy blocks between $from and $to (cached for five minutes) */
    public function busy(ConnectedAccount $account, Carbon $from, Carbon $to): array
    {
        $key = "busy:{$account->id}:{$from->toDateString()}:{$to->toDateString()}";
        $raw = Cache::remember($key, 300, function () use ($account, $from, $to) {
            if ($account->provider === 'google') {
                return $this->client($account)->post('https://www.googleapis.com/calendar/v3/freeBusy', [
                    'timeMin' => $from->toIso8601String(), 'timeMax' => $to->toIso8601String(), 'items' => [['id' => 'primary']],
                ])->json('calendars.primary.busy') ?? [];
            }
            $items = $this->client($account)->post('https://graph.microsoft.com/v1.0/me/calendar/getSchedule', [
                'schedules' => [$account->email],
                'startTime' => ['dateTime' => $from->utc()->format('Y-m-d\TH:i:s'), 'timeZone' => 'UTC'],
                'endTime' => ['dateTime' => $to->utc()->format('Y-m-d\TH:i:s'), 'timeZone' => 'UTC'],
            ])->json('value.0.scheduleItems') ?? [];

            return collect($items)->reject(fn ($i) => ($i['status'] ?? '') === 'free')
                ->map(fn ($i) => ['start' => $i['start']['dateTime'].'Z', 'end' => $i['end']['dateTime'].'Z'])->values()->all();
        });

        return array_map(fn ($b) => [Carbon::parse($b['start'])->utc(), Carbon::parse($b['end'])->utc()], $raw);
    }

    /** Add a meeting to the person's calendar (with the lead invited). Returns the event id. */
    public function createEvent(ConnectedAccount $account, string $title, Carbon $start, int $minutes, string $description, ?string $attendee): ?string
    {
        $end = $start->copy()->addMinutes($minutes);
        if ($account->provider === 'google') {
            return $this->client($account)->post('https://www.googleapis.com/calendar/v3/calendars/primary/events?sendUpdates=all', array_filter([
                'summary' => $title, 'description' => $description,
                'start' => ['dateTime' => $start->toIso8601String()], 'end' => ['dateTime' => $end->toIso8601String()],
                'attendees' => $attendee ? [['email' => $attendee]] : null,
            ]))->json('id');
        }

        return $this->client($account)->post('https://graph.microsoft.com/v1.0/me/events', array_filter([
            'subject' => $title, 'body' => ['contentType' => 'Text', 'content' => $description],
            'start' => ['dateTime' => $start->utc()->format('Y-m-d\TH:i:s'), 'timeZone' => 'UTC'],
            'end' => ['dateTime' => $end->utc()->format('Y-m-d\TH:i:s'), 'timeZone' => 'UTC'],
            'attendees' => $attendee ? [['emailAddress' => ['address' => $attendee], 'type' => 'required']] : null,
        ]))->json('id');
    }

    /** Run a sync and record (rather than throw) failures, so one bad account doesn't stop the rest. */
    public function safely(ConnectedAccount $account, callable $fn): mixed
    {
        try {
            return $fn();
        } catch (Throwable $e) {
            $account->forceFill(['last_error' => mb_substr($e instanceof RuntimeException ? $e->getMessage() : class_basename($e).': '.$e->getMessage(), 0, 490)])->save();
            report($e);

            return null;
        }
    }
}
