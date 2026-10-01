<?php

namespace App\Services;

use App\Jobs\SendBroadcast;
use App\Models\Broadcast;
use App\Models\BroadcastRecipient;
use App\Models\Lead;
use App\Models\Touchpoint;
use App\Support\Tenant;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Email campaigns: pick the audience from a segment, split it for an A/B test,
 * send tracked emails (open pixel + signed click redirects), pick the winning
 * variant and send it to everyone else.
 */
class BroadcastService
{
    public function __construct(
        private LeadSegment $segment,
        private EmailComposer $composer,
        private OrgMailer $mailer,
        private ActivityRecorder $activities,
    ) {}

    /** Leads who will receive it: match the segment, have an email, haven't opted out. */
    public function audience(Broadcast $broadcast): Collection
    {
        return $this->segment->apply(Lead::query(), $broadcast->conditions ?? [])
            ->whereNotNull('email')->whereNull('erased_at')
            ->get(['id', 'organization_id', 'email', 'consent', 'erased_at', 'first_name'])
            ->filter(fn (Lead $l) => $l->canContact('email'))
            ->values();
    }

    public function launch(Broadcast $broadcast): Broadcast
    {
        if (! in_array($broadcast->status, ['draft', 'scheduled'], true)) {
            throw ValidationException::withMessages(['status' => 'This campaign was already sent.']);
        }
        $leads = $this->audience($broadcast)->shuffle();
        if ($leads->isEmpty()) {
            throw ValidationException::withMessages(['conditions' => 'Nobody matches this audience (or everyone opted out of email).']);
        }

        DB::transaction(function () use ($broadcast, $leads) {
            $ab = $broadcast->isAbTest();
            $holdout = $ab && $broadcast->test_percent < 100 && $leads->count() >= 4;
            $testSize = $holdout ? max(2, (int) ceil($leads->count() * $broadcast->test_percent / 100)) : $leads->count();
            foreach ($leads->values() as $i => $lead) {
                $inTest = $i < $testSize;
                BroadcastRecipient::create([
                    'broadcast_id' => $broadcast->id,
                    'lead_id' => $lead->id,
                    'variant' => $inTest ? ($ab ? ($i % 2 === 0 ? 'A' : 'B') : 'A') : null,
                    'status' => $inTest ? 'queued' : 'held',
                    'token' => Str::random(40),
                ]);
            }
            $broadcast->update([
                'status' => $holdout ? 'testing' : 'sending',
                'started_at' => now(),
                'winner_at' => $holdout ? now()->addHours($broadcast->winner_after_hours) : null,
            ]);
        });

        SendBroadcast::dispatch($broadcast->id)->afterCommit();

        return $broadcast->fresh();
    }

    /** Send every queued recipient (called from the queued job). */
    public function sendQueued(Broadcast $broadcast): int
    {
        $sent = 0;
        $broadcast->recipients()->where('status', 'queued')->with('lead')->chunkById(100, function ($recipients) use ($broadcast, &$sent) {
            foreach ($recipients as $recipient) {
                $sent += $this->sendOne($broadcast, $recipient) ? 1 : 0;
            }
        });
        if ($broadcast->status === 'sending' && ! $broadcast->recipients()->whereIn('status', ['queued', 'held'])->exists()) {
            $broadcast->update(['status' => 'sent', 'sent_at' => now()]);
        }

        return $sent;
    }

    private function sendOne(Broadcast $broadcast, BroadcastRecipient $recipient): bool
    {
        $lead = $recipient->lead;
        if (! $lead || ! $lead->email || ! $lead->canContact('email')) {
            $recipient->update(['status' => 'skipped', 'reason' => 'Opted out or no email']);

            return false;
        }
        $variant = $broadcast->variant($recipient->variant ?? 'A') ?? $broadcast->variants[0];
        $subject = $this->composer->render($variant['subject'], $lead, $broadcast->creator);
        $body = $this->composer->render($variant['body'], $lead, $broadcast->creator);
        $text = $body."\n\n—\nDon't want these emails? Unsubscribe: ".$lead->unsubscribeUrl();

        try {
            $provider = $this->mailer->send($lead->organization_id, $lead->email, $lead->full_name, $subject, $text, null, $this->html($body, $recipient->token, $lead));
        } catch (Throwable $e) {
            $recipient->update(['status' => 'failed', 'reason' => mb_substr($e->getMessage(), 0, 190)]);

            return false;
        }
        $recipient->update(['status' => 'sent', 'sent_at' => now()]);
        $this->activities->record($lead, 'email', $subject, [
            'description' => $body, 'direction' => 'outbound', 'outcome' => 'Sent',
            'meta' => ['broadcast_id' => $broadcast->id, 'variant' => $recipient->variant, 'provider' => $provider],
        ]);

        return true;
    }

    /** After the test window: the better variant goes to everyone still waiting. */
    public function pickWinner(Broadcast $broadcast): ?string
    {
        if ($broadcast->status !== 'testing') {
            return null;
        }
        $stats = collect($this->stats($broadcast)['variants']);
        $metric = $broadcast->winner_metric === 'click' ? 'click_rate' : 'open_rate';
        $winner = $stats->sortByDesc(fn ($v) => [$v[$metric], $v['open_rate']])->first()['key'] ?? 'A';

        $broadcast->recipients()->where('status', 'held')->update(['variant' => $winner, 'status' => 'queued']);
        $broadcast->update(['winner_key' => $winner, 'status' => 'sending']);
        SendBroadcast::dispatch($broadcast->id);

        return $winner;
    }

    public function stats(Broadcast $broadcast): array
    {
        $rows = $broadcast->recipients()
            ->selectRaw("variant, count(*) as total, sum(case when status = 'sent' then 1 else 0 end) as sent, sum(case when opened_at is not null then 1 else 0 end) as opened, sum(case when clicked_at is not null then 1 else 0 end) as clicked, sum(case when status = 'held' then 1 else 0 end) as held, sum(case when status in ('skipped', 'failed') then 1 else 0 end) as skipped")
            ->groupBy('variant')->get();
        $rate = fn ($part, $whole) => $whole ? round($part / $whole * 100, 1) : 0.0;
        $variants = collect($broadcast->variants ?? [])->map(function ($v) use ($rows, $rate) {
            $r = $rows->firstWhere('variant', $v['key']);
            $sent = (int) ($r->sent ?? 0);

            return ['key' => $v['key'], 'subject' => $v['subject'], 'sent' => $sent, 'opened' => (int) ($r->opened ?? 0), 'clicked' => (int) ($r->clicked ?? 0),
                'open_rate' => $rate((int) ($r->opened ?? 0), $sent), 'click_rate' => $rate((int) ($r->clicked ?? 0), $sent)];
        })->values();
        $sent = $variants->sum('sent');

        return [
            'recipients' => (int) $rows->sum('total'),
            'sent' => $sent,
            'held' => (int) $rows->sum('held'),
            'skipped' => (int) $rows->sum('skipped'),
            'opened' => $variants->sum('opened'),
            'clicked' => $variants->sum('clicked'),
            'open_rate' => $rate($variants->sum('opened'), $sent),
            'click_rate' => $rate($variants->sum('clicked'), $sent),
            'variants' => $variants->all(),
        ];
    }

    // ------------------------------------------------------------ tracking

    public static function linkSignature(string $token, string $url): string
    {
        return substr(hash_hmac('sha256', "{$token}|{$url}", (string) config('app.key')), 0, 24);
    }

    private function trackUrl(string $token, string $url): string
    {
        return rtrim((string) config('app.url'), '/')."/api/v1/t/c/{$token}/".self::linkSignature($token, $url).'?u='.rawurlencode($url);
    }

    private function html(string $body, string $token, Lead $lead): string
    {
        $escaped = nl2br(e($body));
        $linked = preg_replace_callback('~https?://[^\s<]+~', function ($m) use ($token) {
            $url = html_entity_decode($m[0]);

            return '<a href="'.e($this->trackUrl($token, $url)).'" style="color:#6d28d9">'.e($url).'</a>';
        }, $escaped);
        $pixel = rtrim((string) config('app.url'), '/')."/api/v1/t/o/{$token}.gif";

        return '<!doctype html><html><body style="margin:0;background:#f6f5fb;font-family:-apple-system,Segoe UI,Roboto,sans-serif;color:#1e293b">'
            .'<div style="max-width:600px;margin:0 auto;padding:32px 24px;background:#ffffff;font-size:15px;line-height:1.6">'.$linked.'</div>'
            .'<p style="max-width:600px;margin:0 auto;padding:16px 24px;font-size:12px;color:#94a3b8">Don\'t want these emails? <a href="'.e($lead->unsubscribeUrl()).'" style="color:#94a3b8">Unsubscribe</a></p>'
            .'<img src="'.e($pixel).'" width="1" height="1" alt="" style="display:block;border:0"></body></html>';
    }

    public function trackOpen(string $token): void
    {
        $r = BroadcastRecipient::where('token', $token)->first();
        if ($r) {
            $r->forceFill(['opened_at' => $r->opened_at ?? now(), 'open_count' => $r->open_count + 1])->save();
        }
    }

    /** Record a click and return where to send the person (only URLs we signed). */
    public function trackClick(string $token, string $signature, string $url): ?string
    {
        if (! preg_match('~^https?://~i', $url) || ! hash_equals(self::linkSignature($token, $url), $signature)) {
            return null;
        }
        $r = BroadcastRecipient::with('broadcast', 'lead')->where('token', $token)->first();
        if (! $r) {
            return null;
        }
        $first = ! $r->clicked_at;
        $r->forceFill(['clicked_at' => $r->clicked_at ?? now(), 'opened_at' => $r->opened_at ?? now(), 'click_count' => $r->click_count + 1])->save();
        if ($first && $r->lead) {
            Tenant::run($r->lead->organization_id, function () use ($r, $url) {
                Touchpoint::record($r->lead, 'email_click', $r->broadcast->campaign_id, null, $r->broadcast->name);
                $this->activities->record($r->lead, 'system', "Clicked a link in “{$r->broadcast->name}”", ['description' => $url, 'meta' => ['broadcast_id' => $r->broadcast_id]]);
            });
        }

        return $url;
    }
}
