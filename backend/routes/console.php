<?php

use App\Models\Broadcast;
use App\Models\ConnectedAccount;
use App\Models\Goal;
use App\Models\Lead;
use App\Models\Organization;
use App\Models\SavedReport;
use App\Models\Task;
use App\Models\User;
use App\Notifications\AppNotification;
use App\Notifications\Notifier;
use App\Push\WebPush;
use App\Reports\GoalTracker;
use App\Reports\ReportEngine;
use App\Reports\ReportMailer;
use App\Services\BroadcastService;
use App\Services\ConversionPredictor;
use App\Services\Mailbox;
use App\Services\OrgMailer;
use App\Support\Tenant;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Validation\ValidationException;

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

/*
 * Speed-to-lead target: alert the owner (and their managers) once when a new
 * lead has waited longer than the organization's response target.
 */
Artisan::command('leads:sla', function () {
    $alerted = 0;
    Organization::query()->each(function (Organization $organization) use (&$alerted) {
        Tenant::run($organization->id, function () use ($organization, &$alerted) {
            $hours = ReportEngine::responseTargetHours();
            if (($organization->settings['response_sla_enabled'] ?? true) === false) {
                return;
            }
            Lead::whereNull('first_responded_at')->whereNull('sla_alerted_at')->whereNull('converted_at')
                ->where('created_at', '<=', now()->subMinutes((int) round($hours * 60)))
                ->where('created_at', '>=', now()->subDays(3)) // don't page people about old backlog
                ->with('owner')->limit(200)->get()
                ->each(function (Lead $lead) use ($hours, &$alerted) {
                    $waited = (int) round($lead->created_at->diffInMinutes(now()) / 60);
                    $who = $lead->owner ? collect([$lead->owner]) : collect();
                    $managers = User::whereIn('role', [User::ADMIN, User::MANAGER])->where('is_active', true)->get();
                    $who->merge($managers)->unique('id')->each(fn (User $u) => $u->notify(new AppNotification(
                        "{$lead->full_name} is waiting for a first response",
                        "Captured {$waited}h ago — the target is ".rtrim(rtrim(number_format($hours, 1), '0'), '.').'h.'.($lead->owner ? " Owner: {$lead->owner->name}." : ' Nobody owns it yet.'),
                        "/leads/{$lead->id}",
                        'sla',
                    )));
                    $lead->forceFill(['sla_alerted_at' => now()])->saveQuietly();
                    $alerted++;
                });
        });
    });

    $this->info("Raised {$alerted} speed-to-lead alerts.");
})->purpose('Alert owners about new leads waiting past the response target');

Schedule::command('leads:sla')->everyTenMinutes()->withoutOverlapping();

/*
 * Re-learn each organization's conversion model from its won / lost leads and
 * store the predicted likelihood on open leads (for sorting, lists and reports).
 */
Artisan::command('leads:predict', function (ConversionPredictor $predictor) {
    $total = 0;
    Organization::query()->each(function (Organization $organization) use ($predictor, &$total) {
        $total += Tenant::run($organization->id, fn () => $predictor->refresh());
    });
    $this->info("Scored {$total} open leads.");
})->purpose('Refresh predicted conversion likelihood');

Schedule::command('leads:predict')->hourlyAt(17)->withoutOverlapping();

/*
 * Email campaigns: launch the ones whose scheduled time has come, and finish
 * A/B tests whose test window is over by sending the winner to everyone else.
 */
Artisan::command('broadcasts:run', function (BroadcastService $broadcasts) {
    $launched = 0;
    $finished = 0;
    Broadcast::withoutGlobalScopes()->where(fn ($q) => $q->where('status', 'scheduled')->where('scheduled_at', '<=', now()))
        ->orWhere(fn ($q) => $q->where('status', 'testing')->where('winner_at', '<=', now()))
        ->get()
        ->each(function (Broadcast $broadcast) use ($broadcasts, &$launched, &$finished) {
            Tenant::run($broadcast->organization_id, function () use ($broadcast, $broadcasts, &$launched, &$finished) {
                try {
                    if ($broadcast->status === 'scheduled') {
                        $broadcasts->launch($broadcast);
                        $launched++;
                    } else {
                        $broadcasts->pickWinner($broadcast);
                        $finished++;
                    }
                } catch (ValidationException $e) {
                    $broadcast->update(['status' => 'canceled']);
                    $broadcast->creator?->notify(new AppNotification("“{$broadcast->name}” was not sent", collect($e->errors())->flatten()->first(), '/campaigns?tab=email', 'campaign'));
                }
            });
        });
    $this->info("Launched {$launched}, picked {$finished} winners.");
})->purpose('Launch scheduled email campaigns and finish A/B tests');

Schedule::command('broadcasts:run')->everyFiveMinutes()->withoutOverlapping();

/*
 * Connected Gmail / Outlook mailboxes: bring emails exchanged with leads onto
 * their timelines and into the inbox.
 */
Artisan::command('mailboxes:sync', function (Mailbox $mailbox) {
    $added = 0;
    ConnectedAccount::withoutGlobalScopes()->where('sync_mail', true)->with('user')->get()
        ->filter(fn (ConnectedAccount $a) => $a->user?->is_active)
        ->each(function (ConnectedAccount $account) use ($mailbox, &$added) {
            $added += (int) $mailbox->safely($account, fn () => $mailbox->syncMail($account));
        });
    $this->info("Added {$added} emails.");
})->purpose('Sync connected mailboxes');

Schedule::command('mailboxes:sync')->everyFiveMinutes()->withoutOverlapping();

Artisan::command('push:vapid', function () {
    $keys = WebPush::generateKeys();
    $this->line("VAPID_PUBLIC_KEY={$keys['public']}");
    $this->line("VAPID_PRIVATE_KEY={$keys['private']}");
    $this->comment('Add these to .env. Changing keys signs everyone out of push, so set them once.');
})->purpose('Generate a VAPID key pair for Web Push');

/*
 * Goals: celebrate once per period when one is reached (the person and their
 * managers for a personal goal, everyone for a team goal).
 */
Artisan::command('goals:check', function (GoalTracker $tracker) {
    $sent = 0;
    Organization::query()->each(function (Organization $organization) use ($tracker, &$sent) {
        Tenant::run($organization->id, function () use ($tracker, &$sent) {
            Goal::with('user')->get()->each(function (Goal $goal) use ($tracker, &$sent) {
                $p = $tracker->progress($goal);
                if ($p['status'] !== 'achieved' || $goal->achieved_for === $p['period_label']) {
                    return;
                }
                $goal->forceFill(['achieved_for' => $p['period_label']])->save();
                $title = ($goal->user ? "{$goal->user->name} reached" : 'The team reached')." the {$p['metric_label']} goal";
                $body = "{$p['period_label']}: {$p['actual']} of {$p['target']} ({$p['percent']}%). 🎉";
                $people = $goal->user
                    ? collect([$goal->user])->merge(User::whereIn('role', [User::ADMIN, User::MANAGER])->where('is_active', true)->get())
                    : User::where('is_active', true)->where('role', '!=', User::VIEWER)->get();
                Notifier::many($people, $title, $body, '/reports?tab=goals', 'goal', new User);
                $sent++;
            });
        });
    });
    $this->info("Celebrated {$sent} goals.");
})->purpose('Notify when goals are reached');

Schedule::command('goals:check')->hourlyAt(23)->withoutOverlapping();
