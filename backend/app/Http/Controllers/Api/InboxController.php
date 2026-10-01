<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Activity;
use App\Models\Lead;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Shared conversations inbox: every lead's SMS, WhatsApp and email thread in one
 * place, newest first, with unread replies highlighted. Replies are sent through
 * the existing message / email endpoints.
 */
class InboxController extends Controller
{
    public const CHANNELS = ['sms', 'whatsapp', 'email'];

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $filter = $request->query('filter', 'all');
        $channel = in_array($request->query('channel'), self::CHANNELS, true) ? $request->query('channel') : null;

        $leadIds = Lead::visibleTo($user)
            ->when($filter === 'mine', fn ($q) => $q->where('owner_id', $user->id))
            ->when($request->query('search'), fn ($q, $s) => $q->where(fn ($w) => $w->whereLike('first_name', "%{$s}%")->orWhereLike('last_name', "%{$s}%")->orWhereLike('company', "%{$s}%")))
            ->select('id');

        $threads = Activity::where('subject_type', 'lead')->whereIn('subject_id', $leadIds)
            ->whereIn('type', $channel ? [$channel] : self::CHANNELS)
            ->selectRaw('subject_id, max(occurred_at) as last_at, count(*) as messages')
            ->groupBy('subject_id')->orderByDesc('last_at')->limit(150)->get();

        $leads = Lead::with('owner:id,name,avatar_color')->whereIn('id', $threads->pluck('subject_id'))->get(['id', 'first_name', 'last_name', 'company', 'email', 'phone', 'owner_id', 'inbox_read_at'])->keyBy('id');
        $messages = Activity::where('subject_type', 'lead')->whereIn('subject_id', $threads->pluck('subject_id'))->whereIn('type', self::CHANNELS)
            ->orderByDesc('occurred_at')->limit(3000)->get(['id', 'subject_id', 'type', 'title', 'description', 'direction', 'occurred_at'])->groupBy('subject_id');

        $rows = $threads->map(function ($t) use ($leads, $messages) {
            $lead = $leads[$t->subject_id] ?? null;
            if (! $lead) {
                return null;
            }
            $list = $messages[$t->subject_id] ?? collect();
            $last = $list->first();
            $unread = $list->filter(fn ($m) => $m->direction === 'inbound' && (! $lead->inbox_read_at || $m->occurred_at->greaterThan($lead->inbox_read_at)))->count();

            return [
                'lead' => $lead->only(['id', 'first_name', 'last_name', 'company', 'email', 'phone']) + ['owner' => $lead->owner],
                'last' => $last?->only(['type', 'title', 'description', 'direction', 'occurred_at']),
                'messages' => (int) $t->messages,
                'unread' => $unread,
                'awaiting_reply' => $last?->direction === 'inbound',
            ];
        })->filter()->when($filter === 'unread', fn ($c) => $c->where('unread', '>', 0))
            ->when($filter === 'awaiting', fn ($c) => $c->where('awaiting_reply', true))->values();

        return response()->json(['data' => $rows, 'unread_threads' => $rows->where('unread', '>', 0)->count()]);
    }

    public function show(Request $request, int $leadId): JsonResponse
    {
        $lead = Lead::visibleTo($request->user())->with('owner:id,name,avatar_color', 'status:id,name,color')->findOrFail($leadId);
        $messages = $lead->activities()->whereIn('type', self::CHANNELS)->with('user:id,name')->orderBy('occurred_at')->limit(500)
            ->get(['id', 'type', 'title', 'description', 'direction', 'occurred_at', 'user_id', 'meta']);
        $lead->forceFill(['inbox_read_at' => now()])->saveQuietly();

        return response()->json(['data' => [
            'lead' => $lead->only(['id', 'first_name', 'last_name', 'company', 'email', 'phone', 'owner_id', 'consent']) + ['owner' => $lead->owner, 'status' => $lead->status],
            'messages' => $messages,
        ]]);
    }

    /** Threads with replies nobody has read yet (sidebar badge). */
    public function summary(Request $request): JsonResponse
    {
        $count = Activity::query()
            ->join('leads', fn ($j) => $j->on('leads.id', '=', 'activities.subject_id')->where('activities.subject_type', '=', 'lead'))
            ->whereIn('activities.subject_id', Lead::visibleTo($request->user())->select('id'))
            ->whereIn('activities.type', self::CHANNELS)->where('activities.direction', 'inbound')
            ->where(fn ($q) => $q->whereNull('leads.inbox_read_at')->orWhereColumn('activities.occurred_at', '>', 'leads.inbox_read_at'))
            ->distinct()->count('activities.subject_id');

        return response()->json(['data' => ['unread_threads' => $count]]);
    }
}
