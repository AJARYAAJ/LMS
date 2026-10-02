<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\BookingPage;
use App\Models\ConnectedAccount;
use App\Models\User;
use App\Notifications\AppNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class MailboxTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.google.client_id' => 'gid', 'services.google.client_secret' => 'gsecret']);
        Notification::fake();
    }

    private function discovery(): array
    {
        return [
            'accounts.google.com/.well-known/openid-configuration' => Http::response([
                'authorization_endpoint' => 'https://accounts.google.com/o/oauth2/v2/auth', 'token_endpoint' => 'https://oauth2.googleapis.com/token', 'userinfo_endpoint' => 'https://openidconnect.googleapis.com/v1/userinfo',
            ]),
        ];
    }

    private function connected(User $user): ConnectedAccount
    {
        return $this->inTenant($user, fn () => ConnectedAccount::create([
            'organization_id' => $user->organization_id, 'user_id' => $user->id, 'provider' => 'google', 'email' => 'me@acme.test',
            'access_token' => 'at', 'refresh_token' => 'rt', 'expires_at' => now()->addHour(),
        ]));
    }

    public function test_connecting_google_stores_encrypted_tokens(): void
    {
        $admin = $this->organization();
        Http::fake([...$this->discovery(),
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'at-1', 'refresh_token' => 'rt-1', 'expires_in' => 3600]),
            'openidconnect.googleapis.com/*' => Http::response(['email' => 'Avery@Acme.test', 'email_verified' => true]),
        ]);
        $this->as($admin)->getJson('/api/v1/connected-accounts')->assertJsonPath('data.providers.0.available', true)->assertJsonPath('data.providers.1.available', false);
        $url = $this->as($admin)->postJson('/api/v1/connected-accounts/google/connect')->assertOk()->json('data.url');
        parse_str(parse_url($url, PHP_URL_QUERY), $q);
        $this->assertStringContainsString('gmail.readonly', $q['scope']);
        $this->assertSame('offline', $q['access_type']);

        $this->get('/api/v1/connected-accounts/callback?code=c&state='.$q['state'])->assertRedirectContains('connected=google');
        $account = ConnectedAccount::withoutGlobalScopes()->first();
        $this->assertSame('avery@acme.test', $account->email);
        $this->assertSame('rt-1', $account->refresh_token);
        $this->assertStringNotContainsString('rt-1', (string) \DB::table('connected_accounts')->value('refresh_token'));
        $this->as($admin)->getJson('/api/v1/connected-accounts')->assertJsonMissingPath('data.accounts.0.access_token');
        $this->as($admin)->postJson('/api/v1/connected-accounts/microsoft/connect')->assertStatus(422);
    }

    public function test_gmail_sync_adds_lead_emails_once_and_refreshes_expired_tokens(): void
    {
        $admin = $this->organization();
        $lead = $this->as($admin)->postJson('/api/v1/leads', ['first_name' => 'Lena', 'email' => 'lena@client.test'])->json('data.id');
        $account = $this->connected($admin);
        $account->forceFill(['expires_at' => now()->subMinute()])->save();
        $b64 = fn ($s) => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
        Http::fake([...$this->discovery(),
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'fresh', 'expires_in' => 3600]),
            'gmail.googleapis.com/gmail/v1/users/me/messages?*' => Http::response(['messages' => [['id' => 'm1'], ['id' => 'm2'], ['id' => 'm3']]]),
            'gmail.googleapis.com/gmail/v1/users/me/messages/m1*' => Http::response(['id' => 'm1', 'internalDate' => (string) (now()->subHour()->getTimestampMs()), 'payload' => [
                'headers' => [['name' => 'From', 'value' => 'Lena <LENA@client.test>'], ['name' => 'To', 'value' => 'me@acme.test'], ['name' => 'Subject', 'value' => 'Pricing question']],
                'parts' => [['mimeType' => 'text/plain', 'body' => ['data' => $b64('Can you send pricing?')]]],
            ]]),
            'gmail.googleapis.com/gmail/v1/users/me/messages/m2*' => Http::response(['id' => 'm2', 'internalDate' => (string) now()->getTimestampMs(), 'payload' => [
                'headers' => [['name' => 'From', 'value' => 'me@acme.test'], ['name' => 'To', 'value' => 'lena@client.test'], ['name' => 'Subject', 'value' => 'Re: Pricing question']],
                'mimeType' => 'text/plain', 'body' => ['data' => $b64('Attached!')],
            ]]),
            'gmail.googleapis.com/gmail/v1/users/me/messages/m3*' => Http::response(['id' => 'm3', 'payload' => ['headers' => [['name' => 'From', 'value' => 'news@spam.test']]]]),
        ]);

        $this->as($admin)->postJson("/api/v1/connected-accounts/{$account->id}/sync")->assertOk()->assertJsonPath('message', '2 new emails added to timelines.');
        $this->as($admin)->postJson("/api/v1/connected-accounts/{$account->id}/sync")->assertJsonPath('message', 'Up to date — no new emails with leads.');
        $emails = $this->inTenant($admin, fn () => Activity::where('type', 'email')->where('subject_id', $lead)->orderBy('occurred_at')->get());
        $this->assertSame(['inbound', 'outbound'], $emails->pluck('direction')->all());
        $this->assertSame('Can you send pricing?', $emails[0]->description);
        $this->assertSame('fresh', $account->fresh()->access_token);
        $this->as($admin)->getJson('/api/v1/inbox')->assertJsonPath('data.0.lead.id', $lead);
    }

    public function test_a_broken_connection_alerts_the_person_once(): void
    {
        Notification::fake();
        $admin = $this->organization();
        $account = $this->connected($admin);
        Http::fake(['gmail.googleapis.com/*' => Http::response(['error' => 'invalid_grant'], 401)]);

        $this->artisan('mailboxes:sync')->assertSuccessful();
        $this->artisan('mailboxes:sync')->assertSuccessful();
        $this->assertNotNull($account->fresh()->last_error);
        Notification::assertSentToTimes($admin, AppNotification::class, 1);
        Notification::assertSentTo($admin, AppNotification::class, fn ($n) => $n->kind === 'system' && $n->url === '/profile');
    }

    public function test_emails_to_leads_go_out_through_the_connected_mailbox(): void
    {
        $admin = $this->organization();
        $lead = $this->as($admin)->postJson('/api/v1/leads', ['first_name' => 'Lena', 'email' => 'lena@client.test'])->json('data.id');
        $this->connected($admin);
        Http::fake(['gmail.googleapis.com/gmail/v1/users/me/messages/send' => Http::response(['id' => 'sent-1'])]);

        $this->as($admin)->postJson("/api/v1/leads/{$lead}/email", ['subject' => 'Hello {first_name}', 'body' => 'Hi there'])->assertOk();
        Http::assertSent(function ($r) {
            $raw = base64_decode(strtr($r['raw'], '-_', '+/'));

            return str_contains($raw, 'To: ') && str_contains($raw, 'lena@client.test') && str_contains($raw, '=?UTF-8?B?'.base64_encode('Hello Lena').'?=');
        });
        $this->as($admin)->getJson("/api/v1/leads/{$lead}/activities?type=email")->assertJsonPath('data.0.meta.provider', 'google_mailbox');
    }

    public function test_calendar_busy_times_block_booking_slots_and_bookings_land_on_the_calendar(): void
    {
        Carbon::setTestNow(Carbon::parse('next monday 06:00', 'UTC'));
        $admin = $this->organization();
        $this->connected($admin);
        $monday = now()->toDateString();
        Http::fake([
            'www.googleapis.com/calendar/v3/freeBusy' => Http::response(['calendars' => ['primary' => ['busy' => [['start' => "{$monday}T10:00:00Z", 'end' => "{$monday}T11:00:00Z"]]]]]),
            'www.googleapis.com/calendar/v3/calendars/primary/events*' => Http::response(['id' => 'evt-1']),
        ]);
        $this->inTenant($admin, fn () => BookingPage::create([
            'user_id' => $admin->id, 'slug' => 'avery', 'title' => 'Intro', 'duration_minutes' => 30, 'buffer_minutes' => 0, 'notice_hours' => 0,
            'days_ahead' => 3, 'weekdays' => [1, 2, 3, 4, 5], 'start_time' => '09:00', 'end_time' => '12:00', 'timezone' => 'UTC', 'is_active' => true,
        ]));

        $slots = $this->getJson('/api/v1/public/book/avery')->assertOk()->json("data.slots.{$monday}");
        $times = array_map(fn ($s) => Carbon::parse($s)->format('H:i'), $slots);
        $this->assertSame(['09:00', '09:30', '11:00', '11:30'], $times);

        $this->postJson('/api/v1/public/book/avery', ['name' => 'Bo Booker', 'email' => 'bo@client.test', 'start' => "{$monday}T11:00:00Z"])->assertCreated();
        Http::assertSent(fn ($r) => str_contains($r->url(), '/events') && $r['attendees'][0]['email'] === 'bo@client.test' && $r['summary'] === 'Intro — Bo Booker');
    }
}
