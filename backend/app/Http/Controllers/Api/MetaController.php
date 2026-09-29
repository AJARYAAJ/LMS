<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Integrations\Catalog;
use App\Integrations\IntegrationManager;
use App\Models\AutomationRule;
use App\Models\Campaign;
use App\Models\CustomField;
use App\Models\EmailTemplate;
use App\Models\Integration;
use App\Models\Lead;
use App\Models\LeadSource;
use App\Models\LeadStatus;
use App\Models\PipelineStage;
use App\Models\Sequence;
use App\Models\Tag;
use App\Models\Task;
use App\Models\Team;
use App\Models\User;
use App\Models\WebForm;
use App\Services\AiLeadAdvisor;
use App\Services\ConditionEvaluator;
use App\Services\EmailComposer;
use App\Services\MessagingService;
use App\Services\WebhookDispatcher;
use App\Support\PageLayouts;
use App\Support\Tenant;
use Illuminate\Http\JsonResponse;

/**
 * Lookup data the SPA caches once per session for forms and filters.
 */
class MetaController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return response()->json(['data' => [
            'statuses' => LeadStatus::orderBy('display_order')->get(),
            'sources' => LeadSource::where('is_active', true)->orderBy('name')->get(),
            'campaigns' => Campaign::orderBy('name')->get(['id', 'name', 'status', 'lead_source_id']),
            'tags' => Tag::orderBy('name')->get(),
            'teams' => Team::orderBy('name')->get(['id', 'name', 'color']),
            'users' => User::where('is_active', true)->orderBy('name')->get(['id', 'name', 'email', 'role', 'avatar_color']),
            'stages' => PipelineStage::orderBy('display_order')->get(),
            'custom_fields' => CustomField::orderBy('display_order')->get(),
            'email_templates' => EmailTemplate::orderBy('name')->get(['id', 'name', 'category', 'subject', 'body']),
            'sequences' => Sequence::where('is_active', true)->orderBy('name')->get(['id', 'name', 'description', 'steps']),
            'qualification_criteria' => request()->user()->organization->qualificationCriteria(),
            'layouts' => PageLayouts::all(request()->user()->organization),
            'features' => [
                'ai' => app(AiLeadAdvisor::class)->enabled(),
                'messaging_driver' => app(MessagingService::class)->driver(Tenant::id()),
                'voice' => app(IntegrationManager::class)->active(Tenant::id(), 'voice')?->provider,
                'email_provider' => app(IntegrationManager::class)->active(Tenant::id(), 'email')?->provider ?? 'default',
                'voice_providers' => Integration::query()->where('category', 'voice')->where('is_active', true)->orderBy('id')->get(['id', 'provider'])
                    ->map(fn (Integration $i) => ['id' => $i->id, 'provider' => $i->provider, 'name' => Catalog::get($i->provider)['name'] ?? $i->provider])->values(),
            ],
            'enums' => [
                'priorities' => Lead::PRIORITIES,
                'ratings' => Lead::RATINGS,
                'roles' => User::ROLES,
                'status_categories' => LeadStatus::CATEGORIES,
                'operators' => ConditionEvaluator::OPERATORS,
                'condition_fields' => [...Lead::CONDITION_FIELDS, 'status_key', 'status_category', 'source_key', 'tags', 'qualification_percent', 'last_call_outcome'],
                'automation_triggers' => AutomationRule::TRIGGERS,
                'automation_actions' => AutomationRule::ACTIONS,
                'webhook_events' => WebhookDispatcher::EVENTS,
                'merge_fields' => EmailComposer::MERGE_FIELDS,
                'form_field_keys' => WebForm::FIELD_KEYS,
                'task_types' => Task::TYPES,
            ],
        ]]);
    }
}
