<?php

use App\Models\Lead;
use App\Models\SavedReport;
use App\Models\Task;
use App\Models\User;
use App\Notifications\AppNotification;
use App\Reports\ReportMailer;
use App\Services\OrgMailer;
use App\Support\Tenant;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

/*
 * Notify assignees about tasks whose reminder time (or due time, when no
 * reminder is set) has arrived. Runs every five minutes via the scheduler.
 */
Artisan::command('tasks:remind', function () {
    $sent = 0;

    Task::withoutGlobalScopes()
        ->whereNull('completed_at')
        ->whereNull('reminded_at')
        ->whereNotNull('assigned_to')
        ->where(fn ($q) => $q->where('reminder_at', '<=', now())
            ->orWhere(fn ($q) => $q->whereNull('reminder_at')->where('due_at', '<=', now()->addMinutes(15))))
        ->chunkById(200, function ($tasks) use (&$sent) {
            foreach ($tasks as $task) {
                $user = User::withoutGlobalScopes()->find($task->assigned_to);
                $user?->notify(new AppNotification(
                    $task->due_at?->isPast() ? 'Task overdue' : 'Task due soon',
                    $task->title,
                    $task->taskable_type === 'lead' ? "/leads/{$task->taskable_id}" : '/tasks',
                    'reminder',
                ));
                $task->forceFill(['reminded_at' => now()])->saveQuietly();
                $sent++;
            }
        });

    $this->info("Sent {$sent} reminders.");
})->purpose('Send due task reminders');

Schedule::command('tasks:remind')->everyFiveMinutes()->withoutOverlapping();

/*
 * Morning email digest: today's tasks, overdue follow-ups and new leads,
 * sent through the organization's email vendor to users who keep it on.
 */
Artisan::command('notifications:digest', function (OrgMailer $mailer) {
    $sent = 0;
    User::withoutGlobalScopes()->where('is_active', true)->where('role', '!=', User::VIEWER)->get()
        ->filter(fn (User $u) => (bool) ($u->preferences['digest'] ?? true))
        ->each(function (User $user) use ($mailer, &$sent) {
            Tenant::run($user->organization_id, function () use ($user, $mailer, &$sent) {
                $tasks = Task::where('assigned_to', $user->id)->whereNull('completed_at')->where('due_at', '<=', now()->endOfDay())->orderBy('due_at')->limit(10)->get();
                $overdue = Lead::where('owner_id', $user->id)->whereNull('converted_at')->where('next_follow_up_at', '<', now())->orderBy('next_follow_up_at')->limit(10)->get();
                $new = Lead::where('owner_id', $user->id)->where('assigned_at', '>=', now()->subDay())->limit(10)->get();
                if ($tasks->isEmpty() && $overdue->isEmpty() && $new->isEmpty()) {
                    return;
                }
                $app = rtrim(config('app.frontend_url'), '/');
                $lines = ["Good morning {$user->name}, here is your day in LeadFlow.", ''];
                if ($tasks->isNotEmpty()) {
                    $lines[] = "TASKS DUE TODAY ({$tasks->count()})";
                    $tasks->each(function ($t) use (&$lines) {
                        $lines[] = '• '.$t->title.($t->due_at ? ' — '.$t->due_at->format('H:i') : '');
                    });
                    $lines[] = '';
                }
                if ($overdue->isNotEmpty()) {
                    $lines[] = "OVERDUE FOLLOW-UPS ({$overdue->count()})";
                    $overdue->each(function ($l) use (&$lines, $app) {
                        $lines[] = "• {$l->full_name}".($l->company ? " ({$l->company})" : '')." — {$app}/leads/{$l->id}";
                    });
                    $lines[] = '';
                }
                if ($new->isNotEmpty()) {
                    $lines[] = "NEW LEADS FOR YOU ({$new->count()})";
                    $new->each(function ($l) use (&$lines, $app) {
                        $lines[] = "• {$l->full_name}".($l->company ? " ({$l->company})" : '')." — {$app}/leads/{$l->id}";
                    });
                    $lines[] = '';
                }
                $lines[] = 'Turn this email off under Profile → Notifications.';
                try {
                    $mailer->send($user->organization_id, $user->email, $user->name, 'Your LeadFlow day: '.$tasks->count().' tasks, '.$overdue->count().' overdue', implode("\n", $lines));
                    $sent++;
                } catch (Throwable $e) {
                    report($e);
                }
            });
        });
    $this->info("Sent {$sent} digests.");
})->purpose('Send the morning email digest');

Schedule::command('notifications:digest')->weekdays()->at('07:52');

/*
 * Scheduled report emails: weekly reports go out on Mondays, monthly ones on
 * the 1st, each run with the owner's data visibility.
 */
Artisan::command('reports:send-scheduled', function (ReportMailer $mailer) {
    $sent = 0;
    SavedReport::withoutGlobalScopes()->where('schedule', '!=', 'none')->with('user')->chunkById(100, function ($reports) use ($mailer, &$sent) {
        foreach ($reports as $report) {
            if (! $report->user?->is_active || ! $report->isDue()) {
                continue;
            }
            Tenant::run($report->organization_id, function () use ($report, $mailer, &$sent) {
                try {
                    $mailer->send($report);
                    $report->forceFill(['last_sent_at' => now()])->save();
                    $sent++;
                } catch (Throwable $e) {
                    report($e);
                }
            });
        }
    });

    $this->info("Sent {$sent} scheduled reports.");
})->purpose('Email saved reports that are due');

Schedule::command('reports:send-scheduled')->dailyAt('06:43')->withoutOverlapping();
