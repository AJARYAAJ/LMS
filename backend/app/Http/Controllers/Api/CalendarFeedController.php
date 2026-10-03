<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Task;
use App\Models\User;
use App\Support\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

/** A private iCalendar feed of a person's open tasks and meetings (Google / Outlook / Apple subscribe). */
class CalendarFeedController extends Controller
{
    public function token(Request $request): JsonResponse
    {
        $user = $request->user();
        if ($request->isMethod('post') || ! $user->calendar_token) {
            $user->forceFill(['calendar_token' => Str::random(40)])->save();
        }

        return response()->json(['data' => ['url' => rtrim((string) config('app.url'), '/').'/api/v1/public/calendar/'.$user->calendar_token.'.ics']]);
    }

    public function feed(string $token): Response
    {
        $user = User::withoutGlobalScopes()->where('calendar_token', $token)->where('is_active', true)->firstOrFail();
        $tasks = Tenant::run($user->organization_id, fn () => Task::with('taskable')->where('assigned_to', $user->id)->whereNull('completed_at')
            ->whereNotNull('due_at')->where('due_at', '>=', now()->subDays(30))->orderBy('due_at')->limit(500)->get());

        $esc = fn (?string $v) => str_replace(['\\', ';', ',', "\r\n", "\n"], ['\\\\', '\;', '\,', '\n', '\n'], (string) $v);
        $fmt = fn ($d) => $d->copy()->utc()->format('Ymd\THis\Z');
        $app = rtrim((string) config('app.frontend_url'), '/');
        $lines = ['BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//LeadFlow//Tasks//EN', 'CALSCALE:GREGORIAN', 'X-WR-CALNAME:'.$esc("LeadFlow — {$user->name}")];
        foreach ($tasks as $task) {
            $minutes = preg_match('/\((\d+) min\)/', (string) $task->description, $m) ? (int) $m[1] : ($task->type === 'meeting' ? 30 : 15);
            $url = $task->taskable_type === 'lead' ? "{$app}/leads/{$task->taskable_id}" : "{$app}/tasks";
            array_push($lines,
                'BEGIN:VEVENT',
                "UID:task-{$task->id}@leadflow",
                'DTSTAMP:'.$fmt($task->updated_at ?? now()),
                'DTSTART:'.$fmt($task->due_at),
                'DTEND:'.$fmt($task->due_at->copy()->addMinutes($minutes)),
                'SUMMARY:'.$esc($task->title),
                'DESCRIPTION:'.$esc(trim(($task->description ?? '')."\n".$url)),
                'URL:'.$url,
                'CATEGORIES:'.strtoupper((string) $task->type),
                'END:VEVENT',
            );
        }
        $lines[] = 'END:VCALENDAR';

        return response(implode("\r\n", $lines)."\r\n", 200, ['Content-Type' => 'text/calendar; charset=utf-8', 'Content-Disposition' => 'inline; filename="leadflow.ics"']);
    }
}
