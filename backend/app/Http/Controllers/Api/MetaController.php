<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AutomationRule;
use App\Models\Campaign;
use App\Models\CustomField;
use App\Models\Lead;
use App\Models\LeadSource;
use App\Models\LeadStatus;
use App\Models\PipelineStage;
use App\Models\Tag;
use App\Models\Team;
use App\Models\User;
use App\Services\ConditionEvaluator;
use App\Services\WebhookDispatcher;
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
            'enums' => [
                'priorities' => Lead::PRIORITIES,
                'ratings' => Lead::RATINGS,
                'roles' => User::ROLES,
                'status_categories' => LeadStatus::CATEGORIES,
                'operators' => ConditionEvaluator::OPERATORS,
                'condition_fields' => [...Lead::CONDITION_FIELDS, 'status_key', 'status_category', 'source_key', 'tags'],
                'automation_triggers' => AutomationRule::TRIGGERS,
                'automation_actions' => AutomationRule::ACTIONS,
                'webhook_events' => WebhookDispatcher::EVENTS,
            ],
        ]]);
    }
}
