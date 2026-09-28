<?php

namespace App\Services;

use App\Models\AutomationRule;
use App\Models\LeadSource;
use App\Models\LeadStatus;
use App\Models\Organization;
use App\Models\PipelineStage;
use App\Models\ScoringRule;
use App\Models\Tag;
use App\Models\User;
use App\Support\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Creates an organization with its owner and a sensible, fully editable
 * default configuration (lifecycle, sources, pipeline, scoring, automation).
 */
class OrganizationProvisioner
{
    public const DEFAULT_STATUSES = [
        ['New', 'new', 'open', '#6366f1', true, false],
        ['Contacted', 'contacted', 'open', '#0ea5e9', false, false],
        ['Qualification', 'qualification', 'open', '#8b5cf6', false, false],
        ['Nurturing', 'nurturing', 'open', '#f59e0b', false, false],
        ['Qualified', 'qualified', 'qualified', '#10b981', false, false],
        ['Converted', 'converted', 'converted', '#059669', false, true],
        ['Not Interested', 'not_interested', 'lost', '#94a3b8', false, true],
        ['Lost', 'lost', 'lost', '#ef4444', false, true],
    ];

    public const DEFAULT_SOURCES = [
        ['Website', 'website', '#6366f1'], ['Referral', 'referral', '#10b981'],
        ['Social Media', 'social_media', '#ec4899'], ['Email Campaign', 'email_campaign', '#f59e0b'],
        ['Cold Call', 'cold_call', '#0ea5e9'], ['Trade Show', 'trade_show', '#8b5cf6'],
        ['Partner', 'partner', '#14b8a6'], ['API', 'api', '#64748b'],
    ];

    public const DEFAULT_STAGES = [
        ['Discovery', 10, '#6366f1', false, false], ['Proposal', 30, '#0ea5e9', false, false],
        ['Negotiation', 60, '#f59e0b', false, false], ['Commit', 80, '#8b5cf6', false, false],
        ['Won', 100, '#10b981', true, false], ['Lost', 0, '#ef4444', false, true],
    ];

    public const DEFAULT_SCORING = [
        ['Business email', 'email', 'business_email', null, 20],
        ['Phone number provided', 'phone', 'is_not_empty', null, 10],
        ['Company provided', 'company', 'is_not_empty', null, 10],
        ['Referral source', 'source_key', 'equals', 'referral', 15],
        ['Budget above 10k', 'budget', 'greater_than', '10000', 15],
        ['Decision maker title', 'job_title', 'contains', 'director', 10],
        ['Qualified stage', 'status_category', 'equals', 'qualified', 20],
    ];

    public function provision(array $org, array $owner): User
    {
        return DB::transaction(function () use ($org, $owner) {
            $organization = Organization::create([
                'name' => $org['name'],
                'slug' => $this->uniqueSlug($org['name']),
                'industry' => $org['industry'] ?? null,
                'currency' => $org['currency'] ?? 'USD',
                'timezone' => $org['timezone'] ?? 'UTC',
            ]);

            return Tenant::run($organization->id, function () use ($organization, $owner) {
                $user = User::create(array_merge($owner, [
                    'organization_id' => $organization->id,
                    'role' => User::ADMIN,
                    'avatar_color' => '#6366f1',
                ]));

                $this->seedDefaults($organization);

                return $user;
            });
        });
    }

    public function seedDefaults(Organization $organization): void
    {
        foreach (self::DEFAULT_STATUSES as $i => [$name, $key, $category, $color, $default, $terminal]) {
            LeadStatus::create([
                'organization_id' => $organization->id, 'name' => $name, 'key' => $key, 'category' => $category,
                'color' => $color, 'display_order' => $i, 'is_default' => $default, 'is_terminal' => $terminal,
            ]);
        }

        foreach (self::DEFAULT_SOURCES as [$name, $key, $color]) {
            LeadSource::create(['organization_id' => $organization->id, 'name' => $name, 'key' => $key, 'color' => $color]);
        }

        foreach (self::DEFAULT_STAGES as $i => [$name, $probability, $color, $won, $lost]) {
            PipelineStage::create([
                'organization_id' => $organization->id, 'name' => $name, 'probability' => $probability,
                'color' => $color, 'display_order' => $i, 'is_won' => $won, 'is_lost' => $lost,
            ]);
        }

        foreach (self::DEFAULT_SCORING as [$name, $field, $operator, $value, $points]) {
            ScoringRule::create([
                'organization_id' => $organization->id, 'name' => $name, 'field' => $field,
                'operator' => $operator, 'value' => $value, 'points' => $points,
            ]);
        }

        foreach ([['Hot', '#ef4444'], ['Enterprise', '#6366f1'], ['Follow-up', '#f59e0b'], ['VIP', '#10b981']] as [$name, $color]) {
            Tag::create(['organization_id' => $organization->id, 'name' => $name, 'color' => $color]);
        }

        AutomationRule::create([
            'organization_id' => $organization->id,
            'name' => 'Qualified lead follow-up',
            'description' => 'When a lead becomes qualified, create a call task for the owner and notify them.',
            'trigger' => 'lead.status_changed',
            'conditions' => [['field' => 'status_category', 'operator' => 'equals', 'value' => 'qualified']],
            'actions' => [
                ['type' => 'create_task', 'params' => ['title' => 'Schedule discovery call with {name}', 'task_type' => 'call', 'due_in_hours' => 24]],
                ['type' => 'notify_owner', 'params' => ['title' => 'Lead qualified', 'message' => '{name} from {company} is now qualified.']],
            ],
        ]);
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'org';
        $slug = $base;
        $i = 1;

        while (Organization::where('slug', $slug)->exists()) {
            $slug = $base.'-'.++$i;
        }

        return $slug;
    }
}
