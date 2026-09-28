<?php

use App\Models\Task;
use App\Models\User;
use App\Notifications\AppNotification;
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
