<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BookingPage;
use App\Models\Task;
use App\Models\Touchpoint;
use App\Notifications\AppNotification;
use App\Services\ActivityRecorder;
use App\Services\DuplicateDetector;
use App\Services\LeadService;
use App\Support\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Personal booking pages ("book a meeting with me") and their public side. */
class BookingController extends Controller
{
    public function mine(Request $request): JsonResponse
    {
        $page = BookingPage::where('user_id', $request->user()->id)->first();

        return response()->json(['data' => $page ? [...$page->toArray(), 'url' => $this->url($page)] : null]);
    }

    public function save(Request $request): JsonResponse
    {
        $user = $request->user();
        $page = BookingPage::where('user_id', $user->id)->first();
        $data = $request->validate([
            'slug' => ['required', 'string', 'min:3', 'max:60', 'regex:/^[a-z0-9-]+$/', Rule::unique('booking_pages', 'slug')->ignore($page?->id)],
            'title' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:1000'],
            'duration_minutes' => ['required', 'integer', 'in:15,20,30,45,60,90'],
            'buffer_minutes' => ['sometimes', 'integer', 'between:0,60'],
            'notice_hours' => ['sometimes', 'integer', 'between:0,168'],
            'days_ahead' => ['sometimes', 'integer', 'between:1,60'],
            'weekdays' => ['required', 'array', 'min:1'],
            'weekdays.*' => ['integer', 'between:1,7'],
            'start_time' => ['required', 'date_format:H:i'],
            'end_time' => ['required', 'date_format:H:i', 'after:start_time'],
            'timezone' => ['required', 'timezone'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
        $data['weekdays'] = array_values(array_unique(array_map('intval', $data['weekdays'])));
        $page = $page ? tap($page)->update($data) : BookingPage::create([...$data, 'user_id' => $user->id]);

        return response()->json(['data' => [...$page->fresh()->toArray(), 'url' => $this->url($page)]]);
    }

    public function suggestSlug(Request $request): JsonResponse
    {
        $base = Str::slug($request->user()->name) ?: 'meet';
        $slug = $base;
        for ($i = 2; BookingPage::withoutGlobalScopes()->where('slug', $slug)->exists(); $i++) {
            $slug = "{$base}-{$i}";
        }

        return response()->json(['data' => ['slug' => $slug]]);
    }

    // ------------------------------------------------------------ public

    private function page(string $slug): BookingPage
    {
        return BookingPage::withoutGlobalScopes()->with('user:id,name,job_title,avatar_color,organization_id', 'organization:id,name')
            ->where('slug', $slug)->where('is_active', true)->firstOrFail();
    }

    public function publicShow(string $slug): JsonResponse
    {
        $page = $this->page($slug);

        return response()->json(['data' => [
            'title' => $page->title,
            'description' => $page->description,
            'duration_minutes' => $page->duration_minutes,
            'timezone' => $page->timezone,
            'host' => $page->user->only(['name', 'job_title', 'avatar_color']),
            'organization' => $page->organization->name,
            'slots' => $page->availableSlots(),
        ]]);
    }

    public function book(Request $request, string $slug, LeadService $leads, DuplicateDetector $duplicates, ActivityRecorder $activities): JsonResponse
    {
        $page = $this->page($slug);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:190'],
            'phone' => ['nullable', 'string', 'max:40'],
            'company' => ['nullable', 'string', 'max:120'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'start' => ['required', 'date'],
        ]);
        $start = Carbon::parse($data['start'])->utc();
        $free = collect($page->availableSlots())->flatten()->contains(fn ($s) => Carbon::parse($s)->equalTo($start));
        if (! $free) {
            throw ValidationException::withMessages(['start' => 'That time was just taken. Please pick another slot.']);
        }

        $result = Tenant::run($page->organization_id, function () use ($page, $data, $start, $leads, $duplicates, $activities) {
            [$first, $last] = array_pad(explode(' ', trim($data['name']), 2), 2, null);
            $lead = $duplicates->find($data['email'], $data['phone'] ?? null)->first();
            if ($lead) {
                Touchpoint::record($lead, 'booking_page', null, null, $page->title);
            }
            $lead ??= $leads->create(array_filter([
                'first_name' => $first, 'last_name' => $last, 'email' => $data['email'], 'phone' => $data['phone'] ?? null,
                'company' => $data['company'] ?? null, 'owner_id' => $page->user_id, 'priority' => 'high',
            ]), null, 'booking_page');

            $local = $start->copy()->setTimezone($page->timezone);
            Task::create([
                'taskable_type' => 'lead', 'taskable_id' => $lead->id, 'assigned_to' => $page->user_id,
                'title' => "{$page->title} — {$lead->full_name}", 'type' => 'meeting', 'priority' => 'high', 'due_at' => $start,
                'description' => trim("Booked online ({$page->duration_minutes} min). ".($data['notes'] ?? '')),
            ]);
            $activities->record($lead, 'meeting', "Meeting booked for {$local->format('D M j, H:i')} ({$page->timezone})", [
                'description' => $data['notes'] ?? null, 'direction' => 'inbound', 'meta' => ['booking_page' => $page->slug, 'start' => $start->toIso8601String()],
            ]);
            $lead->forceFill(['next_follow_up_at' => $start])->saveQuietly();
            $page->user->notify(new AppNotification("New meeting: {$lead->full_name}", "{$page->title} on {$local->format('D M j, H:i')}".($lead->company ? " · {$lead->company}" : ''), "/leads/{$lead->id}", 'booking'));

            return ['start' => $start->toIso8601String(), 'local' => $local->format('l, F j \a\t H:i'), 'timezone' => $page->timezone];
        });

        return response()->json(['data' => [...$result, 'host' => $page->user->name, 'title' => $page->title]], 201);
    }

    private function url(BookingPage $page): string
    {
        return rtrim((string) config('app.frontend_url'), '/').'/book/'.$page->slug;
    }
}
