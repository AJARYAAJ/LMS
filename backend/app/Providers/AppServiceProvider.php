<?php

namespace App\Providers;

use App\Models;
use App\Models\Account;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\Lead;
use App\Models\Organization;
use App\Models\User;
use App\Services\AutomationEngine;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // One engine per request/job so its recursion guard is shared.
        $this->app->scoped(AutomationEngine::class);
    }

    public function boot(): void
    {
        // Short, stable type names for polymorphic columns (activities, notes, tasks, audit log).
        Relation::enforceMorphMap(collect([
            Lead::class, Deal::class, Contact::class, Account::class, User::class, Organization::class,
            Models\LeadStatus::class, Models\LeadSource::class, Models\PipelineStage::class, Models\Tag::class,
            Models\Campaign::class, Models\Team::class, Models\CustomField::class, Models\AssignmentRule::class,
            Models\ScoringRule::class, Models\AutomationRule::class, Models\Webhook::class, Models\ApiKey::class,
            Models\Task::class, Models\Note::class, Models\Activity::class, Models\SavedView::class,
            Models\EmailTemplate::class, Models\Sequence::class, Models\SequenceEnrollment::class, Models\WebForm::class,
            Models\Integration::class, Models\AiAgent::class, Models\Call::class, Models\SavedReport::class, Models\Goal::class, Models\Dashboard::class,
        ])->mapWithKeys(fn (string $class) => [Str::snake(class_basename($class)) => $class])->all());

        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(240)->by($request->user()?->id ?: $request->ip()));
        RateLimiter::for('auth', fn (Request $request) => Limit::perMinute(10)->by($request->ip()));
        RateLimiter::for('capture', fn (Request $request) => Limit::perMinute(60)->by($request->header('X-Api-Key') ?: $request->ip()));
    }
}
