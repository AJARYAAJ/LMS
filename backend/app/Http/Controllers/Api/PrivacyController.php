<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Call;
use App\Models\Lead;
use App\Models\User;
use App\Notifications\Notifier;
use App\Services\ActivityRecorder;
use App\Services\AuditLogger;
use App\Support\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Consent per channel, one-click unsubscribe, and GDPR export / erasure for a person. */
class PrivacyController extends Controller
{
    public function __construct(private AuditLogger $audit, private ActivityRecorder $activities) {}

    public function consent(Request $request, int $id): JsonResponse
    {
        $lead = Lead::visibleTo($request->user())->findOrFail($id);
        $data = $request->validate([
            'channel' => ['required', Rule::in(Lead::CONSENT_CHANNELS)],
            'status' => ['required', Rule::in(['granted', 'denied', 'unknown'])],
            'source' => ['nullable', 'string', 'max:60'],
        ]);
        $lead->setConsent($data['channel'], $data['status'], $data['source'] ?? 'manual:'.$request->user()->name);
        $label = ['email' => 'Email', 'sms' => 'SMS', 'whatsapp' => 'WhatsApp', 'calls' => 'Calls'][$data['channel']];
        $this->activities->record($lead, 'system', "{$label} consent: ".['granted' => 'opted in', 'denied' => 'opted out', 'unknown' => 'reset'][$data['status']], ['user_id' => $request->user()->id]);

        return response()->json(['data' => ['consent' => $lead->consent]]);
    }

    /** Public: the link at the bottom of every email. */
    public function unsubscribe(int $lead, string $signature): JsonResponse
    {
        abort_unless(hash_equals(Lead::unsubscribeSignature($lead), $signature), 404);
        $record = Lead::withoutGlobalScopes()->with('organization:id,name')->findOrFail($lead);
        Tenant::run($record->organization_id, function () use ($record) {
            if ($record->consentStatus('email') !== 'denied') {
                $record->setConsent('email', 'denied', 'unsubscribe_link');
                $this->activities->record($record, 'system', 'Unsubscribed from email');
            }
        });

        return response()->json(['data' => ['organization' => $record->organization->name, 'email' => $this->mask($record->email)]]);
    }

    /** Everything stored about one person, as a JSON download (data-portability request). */
    public function export(Request $request, int $id): StreamedResponse
    {
        abort_unless($request->user()->hasRole(User::ADMIN, User::MANAGER), 403, 'Only admins and managers can export personal data.');
        $lead = Lead::visibleTo($request->user())->with(['status:id,name', 'source:id,name', 'owner:id,name', 'tags:id,name'])->findOrFail($id);
        $payload = [
            'exported_at' => now()->toIso8601String(),
            'person' => collect($lead->toArray())->except(['organization_id'])->all(),
            'activities' => $lead->activities()->orderBy('occurred_at')->get(['type', 'title', 'description', 'direction', 'outcome', 'occurred_at'])->toArray(),
            'notes' => $lead->notes()->get(['body', 'created_at'])->toArray(),
            'tasks' => $lead->tasks()->get(['title', 'type', 'due_at', 'completed_at'])->toArray(),
            'calls' => Call::where('lead_id', $lead->id)->get(['created_at', 'status', 'outcome', 'summary', 'transcript', 'duration_seconds'])->toArray(),
        ];
        $this->audit->log('lead.exported', $lead);

        return response()->streamDownload(fn () => print (json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
            'lead-'.$lead->id.'-export.json', ['Content-Type' => 'application/json']);
    }

    /** Right to be forgotten: remove personal data but keep anonymous history for reporting. */
    public function erase(Request $request, int $id): JsonResponse
    {
        abort_unless($request->user()->isAdmin(), 403, 'Only admins can erase personal data.');
        $lead = Lead::findOrFail($id);
        $request->validate(['confirm' => ['required', 'string', Rule::in([$lead->full_name, 'ERASE'])]], ['confirm.in' => 'Type the person’s full name (or ERASE) to confirm.']);
        abort_if($lead->erased_at, 422, 'Already erased.');
        $who = $lead->full_name;

        DB::transaction(function () use ($lead, $request) {
            $lead->forceFill([
                'first_name' => 'Erased', 'last_name' => 'person', 'email' => null, 'phone' => null, 'company' => null, 'job_title' => null,
                'website' => null, 'city' => null, 'state' => null, 'requirements' => null, 'custom_fields' => null, 'qualification' => null,
                'consent' => array_fill_keys(Lead::CONSENT_CHANNELS, ['status' => 'denied', 'at' => now()->toIso8601String(), 'source' => 'erasure']),
                'erased_at' => now(),
            ])->saveQuietly();
            $lead->activities()->update(['description' => null, 'meta' => null]);
            $lead->notes()->delete();
            Call::where('lead_id', $lead->id)->update(['transcript' => null, 'summary' => null, 'recording_url' => null, 'extracted' => null, 'to_number' => '']);
            $this->activities->record($lead, 'system', 'Personal data erased', ['user_id' => $request->user()->id]);
        });
        $this->audit->log('lead.erased', $lead);
        Notifier::managers($lead->organization_id, 'Personal data erased', "{$request->user()->name} erased {$who}’s personal data (GDPR request).", "/leads/{$lead->id}", 'system', adminsOnly: true);

        return response()->json(['message' => 'Personal data erased. Anonymous history is kept for reporting.']);
    }

    private function mask(?string $email): ?string
    {
        if (! $email || ! str_contains($email, '@')) {
            return null;
        }
        [$user, $domain] = explode('@', $email, 2);

        return mb_substr($user, 0, 1).str_repeat('•', max(1, mb_strlen($user) - 1)).'@'.$domain;
    }
}
