<?php

namespace App\Services;

use App\Models\AiAgent;
use App\Models\AutomationRule;
use App\Models\EmailTemplate;
use App\Models\Integration;
use App\Models\LeadSource;
use App\Models\LeadStatus;
use App\Models\Organization;
use App\Models\Pipeline;
use App\Models\PipelineStage;
use App\Models\ScoringRule;
use App\Models\Sequence;
use App\Models\Tag;
use App\Models\User;
use App\Models\WebForm;
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

    public const BLUEPRINT = [
        'qualified' => ['email', 'company'],
        'lost' => ['lost_reason'],
        'not_interested' => ['lost_reason'],
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
                'settings' => ['qualification_criteria' => Organization::DEFAULT_QUALIFICATION],
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
                // Blueprint: what must be known before a lead may enter this stage.
                'required_fields' => self::BLUEPRINT[$key] ?? null,
            ]);
        }

        foreach (self::DEFAULT_SOURCES as [$name, $key, $color]) {
            LeadSource::create(['organization_id' => $organization->id, 'name' => $name, 'key' => $key, 'color' => $color]);
        }

        $pipeline = Pipeline::create(['organization_id' => $organization->id, 'name' => 'Sales', 'is_default' => true]);
        foreach (self::DEFAULT_STAGES as $i => [$name, $probability, $color, $won, $lost]) {
            PipelineStage::create([
                'organization_id' => $organization->id, 'pipeline_id' => $pipeline->id, 'name' => $name, 'probability' => $probability,
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

        $this->seedPlaybooks($organization);
        $this->seedCalling($organization);

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

    private function seedPlaybooks(Organization $organization): void
    {
        $intro = EmailTemplate::create([
            'organization_id' => $organization->id,
            'name' => 'Intro — thanks for reaching out',
            'category' => 'outreach',
            'subject' => 'Great to meet you, {first_name}',
            'body' => "Hi {first_name},\n\nThanks for your interest in {organization.name}. I'd love to learn more about what {company} is looking for.\n\nWould you have 20 minutes this week for a quick call?\n\nBest,\n{sender.name}",
        ]);
        $followUp = EmailTemplate::create([
            'organization_id' => $organization->id,
            'name' => 'Follow-up — checking in',
            'category' => 'follow_up',
            'subject' => 'Quick follow-up, {first_name}',
            'body' => "Hi {first_name},\n\nJust checking in on my last note. Happy to share a short demo tailored to {company} whenever suits you.\n\nBest,\n{sender.name}",
        ]);
        EmailTemplate::create([
            'organization_id' => $organization->id,
            'name' => 'Proposal sent',
            'category' => 'proposal',
            'subject' => 'Your proposal from {organization.name}',
            'body' => "Hi {first_name},\n\nAs promised, here is the proposal we discussed. Let me know if you have any questions — I'm glad to walk your team through it.\n\nBest,\n{sender.name}",
        ]);

        Sequence::create([
            'organization_id' => $organization->id,
            'name' => 'New inbound lead — 7 day cadence',
            'description' => 'Call, email and follow up within the first week.',
            'steps' => [
                ['day_offset' => 0, 'type' => 'call', 'title' => 'Intro call'],
                ['day_offset' => 0, 'type' => 'email', 'title' => 'Send intro email', 'email_template_id' => $intro->id],
                ['day_offset' => 2, 'type' => 'call', 'title' => 'Second call attempt'],
                ['day_offset' => 4, 'type' => 'email', 'title' => 'Follow-up email', 'email_template_id' => $followUp->id],
                ['day_offset' => 7, 'type' => 'follow_up', 'title' => 'Decide: qualify or nurture'],
            ],
        ]);
        Sequence::create([
            'organization_id' => $organization->id,
            'name' => 'Nurture — monthly check-in',
            'description' => 'Keep cold or lost leads warm.',
            'steps' => [
                ['day_offset' => 14, 'type' => 'email', 'title' => 'Share a case study', 'email_template_id' => $followUp->id],
                ['day_offset' => 45, 'type' => 'email', 'title' => 'Product update email'],
                ['day_offset' => 90, 'type' => 'call', 'title' => 'Re-qualification call'],
            ],
        ]);

        WebForm::create([
            'organization_id' => $organization->id,
            'name' => 'Website — Contact sales',
            'slug' => Str::slug($organization->slug.'-contact-sales'),
            'title' => 'Talk to our team',
            'description' => 'Tell us a little about you and we will reach out within one business day.',
            'fields' => [
                ['key' => 'name', 'label' => 'Full name', 'type' => 'text', 'required' => true],
                ['key' => 'email', 'label' => 'Work email', 'type' => 'email', 'required' => true],
                ['key' => 'company', 'label' => 'Company', 'type' => 'text', 'required' => false],
                ['key' => 'phone', 'label' => 'Phone', 'type' => 'tel', 'required' => false],
                ['key' => 'requirements', 'label' => 'How can we help?', 'type' => 'textarea', 'required' => false],
            ],
            'lead_source_id' => LeadSource::where('organization_id', $organization->id)->where('key', 'website')->value('id'),
        ]);
    }

    /** Call simulator + a ready-to-use AI qualifier agent, so AI calling works out of the box. */
    private function seedCalling(Organization $organization): void
    {
        $simulator = Integration::create([
            'organization_id' => $organization->id, 'category' => 'voice', 'provider' => 'simulator', 'config' => [],
        ]);

        AiAgent::create([
            'organization_id' => $organization->id,
            'name' => 'Ava — inbound qualifier',
            'integration_id' => $simulator->id,
            'goal' => 'Qualify new inbound leads (budget, authority, need, timeline) and book a 30-minute demo with a specialist.',
            'first_message' => 'Hi {first_name}, this is Ava from {organization} — you recently showed interest in what we do. Do you have two minutes?',
            'voice' => 'nova',
            'language' => 'en-US',
            'questions' => [
                ['key' => 'need', 'question' => 'What prompted you to look for a solution right now?'],
                ['key' => 'budget', 'question' => 'Have you set aside a budget for this?'],
                ['key' => 'authority', 'question' => 'Who else is involved in making the decision?'],
                ['key' => 'timeline', 'question' => 'When would you ideally like to have something in place?'],
            ],
            'max_duration_seconds' => 300,
        ]);

        AiAgent::create([
            'organization_id' => $organization->id,
            'mode' => 'inbound',
            'transfer_mode' => 'owner',
            'name' => 'Max — AI receptionist',
            'integration_id' => $simulator->id,
            'goal' => 'Answer incoming calls, find out who is calling and what they need, qualify new enquiries and book a call with the right person.',
            'first_message' => 'Thanks for calling {organization}, this is Max. Who do I have the pleasure of speaking with?',
            'voice' => 'echo',
            'language' => 'en-US',
            'questions' => [
                ['key' => 'need', 'question' => 'What can we help you with today?'],
                ['key' => 'timeline', 'question' => 'When are you hoping to get this sorted?'],
            ],
            'max_duration_seconds' => 600,
        ]);
    }
}
