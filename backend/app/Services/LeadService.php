<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\Lead;
use App\Models\LeadStatus;
use App\Models\PipelineStage;
use App\Models\User;
use App\Notifications\AppNotification;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Orchestrates the lead lifecycle:
 * capture → score → assign → activities → status changes → conversion,
 * firing automations, webhooks, notifications and audit entries on the way.
 */
class LeadService
{
    public function __construct(
        private ScoringEngine $scoring,
        private AssignmentEngine $assignment,
        private AutomationEngine $automation,
        private WebhookDispatcher $webhooks,
        private ActivityRecorder $activities,
        private AuditLogger $audit,
        private LeadEnricher $enricher,
    ) {}

    public function create(array $data, ?User $actor, string $channel = 'manual'): Lead
    {
        $tags = Arr::pull($data, 'tag_ids');

        $lead = DB::transaction(function () use ($data, $actor, $channel, $tags) {
            $data['lead_status_id'] ??= LeadStatus::where('is_default', true)->value('id')
                ?? LeadStatus::orderBy('display_order')->value('id');
            $data['created_by'] = $actor?->id;

            $lead = Lead::create($data);

            if ($tags !== null) {
                $lead->tags()->sync($tags);
            }

            $lead->statusHistory()->create([
                'to_status_id' => $lead->lead_status_id,
                'user_id' => $actor?->id,
                'note' => 'Lead created',
                'created_at' => now(),
            ]);

            $this->activities->record($lead, 'system', 'Lead created', [
                'description' => 'Captured via '.str_replace('_', ' ', $channel),
                'meta' => ['channel' => $channel],
            ]);

            if ($enriched = $this->enricher->enrich($lead)) {
                $this->activities->record($lead, 'system', 'Lead enriched', [
                    'description' => collect($enriched)->map(fn ($v, $k) => str_replace('_', ' ', $k).": {$v}")->implode(' · '),
                ]);
            }

            $this->scoring->recalculate($lead);

            if ($lead->owner_id) {
                $lead->forceFill(['assigned_at' => now()])->saveQuietly();
            } else {
                $this->autoAssign($lead);
            }

            $this->audit->log('lead.created', $lead, [], $lead->only(['first_name', 'last_name', 'email', 'company', 'owner_id', 'lead_status_id']));

            return $lead;
        });

        $this->afterEvent('lead.created', $lead);

        return $lead->fresh();
    }

    public function update(Lead $lead, array $data, ?User $actor): Lead
    {
        $tags = Arr::pull($data, 'tag_ids');
        $statusId = Arr::pull($data, 'lead_status_id');
        $ownerId = array_key_exists('owner_id', $data) ? Arr::pull($data, 'owner_id') : false;

        DB::transaction(function () use ($lead, $data, $tags) {
            $original = $lead->getOriginal();
            $lead->fill($data)->save();

            if ($tags !== null) {
                $lead->tags()->sync($tags);
            }

            $this->audit->logChanges('lead.updated', $lead, $original);
            $this->scoring->recalculate($lead);
        });

        $this->afterEvent('lead.updated', $lead);

        if ($statusId && (int) $statusId !== $lead->lead_status_id) {
            $this->changeStatus($lead, (int) $statusId, $actor);
        }

        if ($ownerId !== false) {
            $ownerId = $ownerId ? (int) $ownerId : null;
            if ($ownerId !== $lead->owner_id) {
                $this->assign($lead, $ownerId, $actor);
            }
        }

        return $lead->fresh();
    }

    public function changeStatus(Lead $lead, int $statusId, ?User $actor, ?string $note = null, ?string $lostReason = null): Lead
    {
        $status = LeadStatus::findOrFail($statusId);
        $from = $lead->status;

        if ($from?->id === $status->id) {
            return $lead;
        }

        $this->enforceBlueprint($lead, $status, $lostReason);

        DB::transaction(function () use ($lead, $status, $from, $actor, $note, $lostReason) {
            $lead->lead_status_id = $status->id;

            if ($status->category === 'qualified' && ! $lead->qualified_at) {
                $lead->qualified_at = now();
            }
            if ($status->category === 'lost') {
                $lead->lost_reason = $lostReason ?? $lead->lost_reason;
            }
            $lead->save();

            $lead->statusHistory()->create([
                'from_status_id' => $from?->id,
                'to_status_id' => $status->id,
                'user_id' => $actor?->id,
                'note' => $note,
                'created_at' => now(),
            ]);

            $this->activities->record($lead, 'system', "Status changed to {$status->name}", [
                'description' => $note ?? ($from ? "From {$from->name}" : null),
                'meta' => ['from' => $from?->key, 'to' => $status->key],
            ]);

            $this->audit->log('lead.status_changed', $lead, ['status' => $from?->key], ['status' => $status->key]);
            $this->scoring->recalculate($lead);
        });

        $this->afterEvent('lead.status_changed', $lead->refresh());

        return $lead;
    }

    public function assign(Lead $lead, ?int $userId, ?User $actor, ?string $reason = null): Lead
    {
        $user = $userId ? User::where('is_active', true)->findOrFail($userId) : null;
        $previous = $lead->owner_id;

        DB::transaction(function () use ($lead, $user, $previous, $reason) {
            $lead->forceFill(['owner_id' => $user?->id, 'assigned_at' => $user ? now() : null])->save();

            $this->activities->record($lead, 'system', $user ? "Assigned to {$user->name}" : 'Moved to unassigned queue', [
                'description' => $reason,
            ]);
            $this->audit->log('lead.assigned', $lead, ['owner_id' => $previous], ['owner_id' => $user?->id]);
        });

        if ($user && $user->id !== $actor?->id) {
            $user->notify(new AppNotification(
                'New lead assigned',
                "{$lead->full_name}".($lead->company ? " ({$lead->company})" : '').' has been assigned to you.',
                "/leads/{$lead->id}",
                'assignment',
            ));
        }

        $this->afterEvent('lead.assigned', $lead);

        return $lead;
    }

    /**
     * Blueprint: a lead may only enter a status once its required fields are known.
     */
    public function enforceBlueprint(Lead $lead, LeadStatus $status, ?string $lostReason = null): void
    {
        $missing = collect($status->required_fields ?? [])
            ->filter(fn (string $field) => blank($field === 'lost_reason' ? ($lostReason ?? $lead->lost_reason) : $lead->{$field}))
            ->values();

        if ($missing->isNotEmpty()) {
            throw ValidationException::withMessages([
                'lead_status_id' => "Before moving to {$status->name}, fill in: ".$missing->map(fn ($f) => str_replace('_', ' ', $f))->implode(', ').'.',
                'required_fields' => $missing->all(),
            ]);
        }
    }

    /**
     * Run the assignment engine for a lead that has no owner.
     */
    public function autoAssign(Lead $lead): ?User
    {
        ['user' => $user, 'rule' => $rule] = $this->assignment->resolve($lead);

        if ($user) {
            $lead->forceFill(['owner_id' => $user->id, 'assigned_at' => now()])->saveQuietly();
            $this->activities->record($lead, 'system', "Auto-assigned to {$user->name}", [
                'description' => "Rule: {$rule->name} ({$rule->strategy})",
            ]);
            $user->notify(new AppNotification(
                'New lead assigned',
                "{$lead->full_name} was routed to you by \"{$rule->name}\".",
                "/leads/{$lead->id}",
                'assignment',
            ));
        }

        return $user;
    }

    /**
     * Lead → Contact + Account (+ optional Deal).
     *
     * @param  array{create_account?: bool, account_id?: ?int, create_deal?: bool, deal_name?: ?string, deal_amount?: ?float, pipeline_stage_id?: ?int, expected_close_date?: ?string}  $options
     */
    public function convert(Lead $lead, array $options, ?User $actor): Lead
    {
        if ($lead->isConverted()) {
            throw ValidationException::withMessages(['lead' => 'This lead has already been converted.']);
        }

        DB::transaction(function () use ($lead, $options, $actor) {
            $account = null;

            if (! empty($options['account_id'])) {
                $account = Account::findOrFail($options['account_id']);
            } elseif (($options['create_account'] ?? true) && $lead->company) {
                $account = Account::firstOrCreate(
                    ['name' => $lead->company],
                    [
                        'website' => $lead->website,
                        'industry' => $lead->industry,
                        'company_size' => $lead->company_size,
                        'phone' => $lead->phone,
                        'city' => $lead->city,
                        'country' => $lead->country,
                        'owner_id' => $lead->owner_id,
                    ],
                );
            }

            $contact = Contact::create([
                'account_id' => $account?->id,
                'first_name' => $lead->first_name,
                'last_name' => $lead->last_name,
                'email' => $lead->email,
                'phone' => $lead->phone,
                'job_title' => $lead->job_title,
                'owner_id' => $lead->owner_id,
                'lead_id' => $lead->id,
            ]);

            $deal = null;
            if ($options['create_deal'] ?? true) {
                $stage = ! empty($options['pipeline_stage_id'])
                    ? PipelineStage::findOrFail($options['pipeline_stage_id'])
                    : PipelineStage::where('is_won', false)->where('is_lost', false)->orderBy('display_order')->first();

                $deal = Deal::create([
                    'name' => $options['deal_name'] ?? trim(($lead->company ?: $lead->full_name).' deal'),
                    'account_id' => $account?->id,
                    'contact_id' => $contact->id,
                    'lead_id' => $lead->id,
                    'pipeline_stage_id' => $stage?->id,
                    'owner_id' => $lead->owner_id,
                    'amount' => $options['deal_amount'] ?? $lead->expected_value ?? 0,
                    'probability' => $stage?->probability ?? 10,
                    'expected_close_date' => $options['expected_close_date'] ?? null,
                ]);
            }

            $convertedStatus = LeadStatus::where('category', 'converted')->orderBy('display_order')->first();

            $lead->forceFill([
                'converted_at' => now(),
                'converted_contact_id' => $contact->id,
                'converted_account_id' => $account?->id,
                'converted_deal_id' => $deal?->id,
            ])->save();

            if ($convertedStatus && $convertedStatus->id !== $lead->lead_status_id) {
                $lead->statusHistory()->create([
                    'from_status_id' => $lead->lead_status_id,
                    'to_status_id' => $convertedStatus->id,
                    'user_id' => $actor?->id,
                    'note' => 'Converted',
                    'created_at' => now(),
                ]);
                $lead->forceFill(['lead_status_id' => $convertedStatus->id])->save();
            }

            $this->activities->record($lead, 'system', 'Lead converted', [
                'description' => collect([
                    "Contact: {$contact->full_name}",
                    $account ? "Account: {$account->name}" : null,
                    $deal ? "Deal: {$deal->name}" : null,
                ])->filter()->implode(' · '),
            ]);

            if ($deal) {
                $this->activities->record($deal, 'system', 'Deal created from lead', ['description' => $lead->full_name]);
            }

            $this->audit->log('lead.converted', $lead, [], [
                'contact_id' => $contact->id,
                'account_id' => $account?->id,
                'deal_id' => $deal?->id,
            ]);
        });

        $this->afterEvent('lead.converted', $lead);

        return $lead->fresh(['convertedContact', 'convertedAccount', 'convertedDeal']);
    }

    public function delete(Lead $lead): void
    {
        $this->audit->log('lead.deleted', $lead, $lead->only(['first_name', 'last_name', 'email', 'company']));
        $payload = $this->payload($lead);
        $lead->delete();
        $this->webhooks->dispatch($lead->organization_id, 'lead.deleted', $payload);
    }

    private function afterEvent(string $event, Lead $lead): void
    {
        $this->automation->fire($event, $lead);
        $this->webhooks->dispatch($lead->organization_id, $event, $this->payload($lead->fresh() ?? $lead));
    }

    private function payload(Lead $lead): array
    {
        $lead->loadMissing(['status:id,name,key', 'source:id,name,key', 'owner:id,name,email', 'tags:id,name']);

        return $lead->toArray();
    }
}
