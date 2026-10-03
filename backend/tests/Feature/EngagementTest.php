<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\Call;
use App\Models\Integration;
use App\Models\Lead;
use App\Models\Task;
use App\Notifications\AppNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class EngagementTest extends TestCase
{
    use RefreshDatabase;

    private function message(int $leadId, string $type, string $direction, Carbon $at): void
    {
        Activity::create(['subject_type' => 'lead', 'subject_id' => $leadId, 'type' => $type, 'title' => "{$direction} {$type}", 'description' => "Body {$type}", 'direction' => $direction, 'occurred_at' => $at]);
    }

    public function test_inbox_groups_threads_and_tracks_unread_replies(): void
    {
        $admin = $this->organization();
        $rep = $this->member($admin);
        $a = $this->as($admin)->postJson('/api/v1/leads', ['first_name' => 'Ana', 'phone' => '+15550000001', 'owner_id' => $rep->id])->json('data.id');
        $b = $this->as($admin)->postJson('/api/v1/leads', ['first_name' => 'Ben', 'email' => 'ben@x.test'])->json('data.id');
        $this->inTenant($admin, function () use ($a, $b) {
            $this->message($a, 'sms', 'outbound', now()->subHours(3));
            $this->message($a, 'sms', 'inbound', now()->subHours(2));
            $this->message($a, 'whatsapp', 'inbound', now()->subHour());
            $this->message($b, 'email', 'outbound', now()->subMinutes(30));
        });

        $res = $this->as($admin)->getJson('/api/v1/inbox')->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('unread_threads', 1);
        $this->assertSame($b, $res->json('data.0.lead.id')); // newest first
        $ana = collect($res->json('data'))->firstWhere('lead.id', $a);
        $this->assertSame(2, $ana['unread']);
        $this->assertTrue($ana['awaiting_reply']);
        $this->as($admin)->getJson('/api/v1/inbox?filter=unread')->assertJsonCount(1, 'data');
        $this->as($admin)->getJson('/api/v1/inbox?channel=email')->assertJsonCount(1, 'data');
        $this->as($admin)->getJson('/api/v1/inbox/summary')->assertJsonPath('data.unread_threads', 1);

        $this->as($admin)->getJson("/api/v1/inbox/{$a}")->assertOk()->assertJsonCount(3, 'data.messages')->assertJsonPath('data.messages.0.direction', 'outbound');
        $this->as($admin)->getJson('/api/v1/inbox/summary')->assertJsonPath('data.unread_threads', 0);

        // Reps only see their own leads' conversations.
        $this->as($rep)->getJson('/api/v1/inbox')->assertJsonCount(1, 'data')->assertJsonPath('data.0.lead.id', $a);
        $this->as($rep)->getJson("/api/v1/inbox/{$b}")->assertNotFound();
    }

    public function test_booking_page_offers_free_slots_and_books_a_meeting(): void
    {
        Notification::fake();
        Carbon::setTestNow(Carbon::parse('next monday 08:00', 'UTC'));
        $admin = $this->organization();
        $rep = $this->member($admin);

        $this->as($rep)->putJson('/api/v1/booking-page', ['slug' => 'Bad Slug', 'title' => 'x', 'duration_minutes' => 30, 'weekdays' => [1], 'start_time' => '09:00', 'end_time' => '17:00', 'timezone' => 'UTC'])->assertUnprocessable();
        $this->as($rep)->putJson('/api/v1/booking-page', ['slug' => 'riley', 'title' => 'Discovery', 'duration_minutes' => 30, 'buffer_minutes' => 0, 'notice_hours' => 1, 'days_ahead' => 2, 'weekdays' => [1, 2], 'start_time' => '09:00', 'end_time' => '11:00', 'timezone' => 'UTC'])
            ->assertOk()->assertJsonPath('data.slug', 'riley');
        $this->as($admin)->putJson('/api/v1/booking-page', ['slug' => 'riley', 'title' => 'x', 'duration_minutes' => 30, 'weekdays' => [1], 'start_time' => '09:00', 'end_time' => '17:00', 'timezone' => 'UTC'])->assertUnprocessable();

        // An existing meeting blocks its slot.
        $this->inTenant($admin, fn () => Task::create(['title' => 'Busy', 'type' => 'meeting', 'assigned_to' => $rep->id, 'due_at' => now()->setTime(9, 30)]));

        $slots = $this->getJson('/api/v1/public/book/riley')->assertOk()->assertJsonPath('data.host.name', $rep->name)->json('data.slots');
        $monday = now()->toDateString();
        $this->assertSame([now()->setTime(9, 0)->toIso8601String(), now()->setTime(10, 0)->toIso8601String(), now()->setTime(10, 30)->toIso8601String()], $slots[$monday]);
        $this->assertCount(4, $slots[now()->addDay()->toDateString()]);
        $this->assertArrayNotHasKey(now()->addDays(2)->toDateString(), $slots); // Wednesday isn't offered

        $start = now()->setTime(10, 0)->toIso8601String();
        $this->postJson('/api/v1/public/book/riley', ['name' => 'Grace Hopper', 'email' => 'grace@navy.test', 'company' => 'Navy', 'start' => $start])->assertCreated()->assertJsonPath('data.host', $rep->name);
        $this->postJson('/api/v1/public/book/riley', ['name' => 'Late', 'email' => 'late@x.test', 'start' => $start])->assertUnprocessable();

        $lead = $this->inTenant($admin, fn () => Lead::where('email', 'grace@navy.test')->first());
        $this->assertSame($rep->id, $lead->owner_id);
        $this->assertSame('Navy', $lead->company);
        $this->assertTrue($this->inTenant($admin, fn () => Task::where('taskable_id', $lead->id)->where('type', 'meeting')->where('assigned_to', $rep->id)->exists()));
        Notification::assertSentTo($rep, AppNotification::class, fn ($n) => $n->kind === 'booking');

        // Booking again with the same email reuses the lead.
        $this->postJson('/api/v1/public/book/riley', ['name' => 'Grace Hopper', 'email' => 'grace@navy.test', 'start' => now()->setTime(10, 30)->toIso8601String()])->assertCreated();
        $this->assertSame(1, $this->inTenant($admin, fn () => Lead::where('email', 'grace@navy.test')->count()));
        $this->getJson('/api/v1/public/book/nobody')->assertNotFound();
        Carbon::setTestNow();
    }

    public function test_calendar_feed_lists_open_tasks(): void
    {
        $admin = $this->organization();
        $this->inTenant($admin, fn () => Task::create(['title' => 'Demo, with Acme', 'type' => 'meeting', 'assigned_to' => $admin->id, 'due_at' => now()->addDay()->setTime(14, 0), 'description' => 'Booked online (45 min).']));

        $url = $this->as($admin)->getJson('/api/v1/auth/calendar-feed')->assertOk()->json('data.url');
        $path = parse_url($url, PHP_URL_PATH);
        $ics = $this->get($path)->assertOk()->assertHeader('Content-Type', 'text/calendar; charset=utf-8')->getContent();
        $this->assertStringContainsString('BEGIN:VCALENDAR', $ics);
        $this->assertStringContainsString('SUMMARY:Demo\, with Acme', $ics);
        $this->assertStringContainsString('DTEND:'.now()->addDay()->setTime(14, 45)->utc()->format('Ymd\THis\Z'), $ics);

        $new = $this->as($admin)->postJson('/api/v1/auth/calendar-feed')->json('data.url');
        $this->assertNotSame($url, $new);
        $this->get($path)->assertNotFound(); // the old link stops working
    }

    public function test_call_notes_are_analysed_like_ai_calls(): void
    {
        $admin = $this->organization();
        $id = $this->as($admin)->postJson('/api/v1/leads', ['first_name' => 'Ada', 'phone' => '+15550001234'])->json('data.id');

        $this->as($admin)->postJson("/api/v1/leads/{$id}/call-notes", [
            'notes' => "Me: Thanks for taking the call. Is a demo useful?\nAda: Yes, we need better visibility into the pipeline. Our budget is set aside for this quarter.\nAda: Tuesday at 2pm works for me.",
            'duration_minutes' => 12,
        ])->assertCreated()->assertJsonPath('data.provider', 'manual')->assertJsonPath('data.status', 'completed')->assertJsonPath('data.outcome', 'meeting_booked');

        $call = $this->inTenant($admin, fn () => Call::where('lead_id', $id)->first());
        $this->assertSame('agent', $call->transcript[0]['role']);
        $this->assertSame(720, $call->duration_seconds);
        $this->as($admin)->getJson("/api/v1/leads/{$id}/activities")->assertJsonFragment(['title' => 'Call — Meeting Booked']);
        $lead = $this->inTenant($admin, fn () => Lead::find($id));
        $this->assertTrue($lead->qualification['budget'] ?? false);
        $this->assertTrue($this->inTenant($admin, fn () => Task::where('taskable_id', $id)->where('type', 'meeting')->exists()));
        $this->assertNotNull($lead->first_responded_at);

        $this->as($admin)->postJson("/api/v1/leads/{$id}/call-notes", [])->assertUnprocessable();
        $this->as($admin)->post("/api/v1/leads/{$id}/call-notes", ['audio' => UploadedFile::fake()->create('call.mp3', 100, 'audio/mpeg')], ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonValidationErrors('audio');
    }

    public function test_recordings_are_transcribed_by_deepgram(): void
    {
        Http::fake(['api.deepgram.com/*' => Http::response(['results' => ['utterances' => [
            ['speaker' => 0, 'transcript' => 'Hi, this is Sam from Acme.'],
            ['speaker' => 1, 'transcript' => 'Not interested, we just renewed with another vendor.'],
        ]]])]);
        $admin = $this->organization();
        $this->inTenant($admin, fn () => Integration::create(['category' => 'transcription', 'provider' => 'deepgram', 'config' => ['api_key' => 'dg-key'], 'is_active' => true, 'status' => 'connected']));
        $id = $this->as($admin)->postJson('/api/v1/leads', ['first_name' => 'Bo'])->json('data.id');

        $this->as($admin)->post("/api/v1/leads/{$id}/call-notes", ['audio' => UploadedFile::fake()->create('call.mp3', 200, 'audio/mpeg')], ['Accept' => 'application/json'])
            ->assertCreated()->assertJsonPath('data.outcome', 'not_interested');
        Http::assertSent(fn ($r) => str_contains($r->url(), 'api.deepgram.com/v1/listen') && str_contains($r->url(), 'diarize=true') && $r->hasHeader('Authorization', 'Token dg-key'));
        $call = $this->inTenant($admin, fn () => Call::where('lead_id', $id)->first());
        $this->assertSame(['agent', 'lead'], array_column($call->transcript, 'role'));
    }
}
