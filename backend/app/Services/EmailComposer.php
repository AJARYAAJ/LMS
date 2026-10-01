<?php

namespace App\Services;

use App\Models\EmailTemplate;
use App\Models\Lead;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;

/**
 * Renders merge-field templates and sends one-to-one emails to leads,
 * logging every message on the lead timeline.
 */
class EmailComposer
{
    public const MERGE_FIELDS = [
        '{first_name}', '{last_name}', '{name}', '{company}', '{job_title}', '{email}',
        '{owner.name}', '{owner.email}', '{sender.name}', '{organization.name}', '{unsubscribe_url}',
    ];

    public function __construct(private ActivityRecorder $activities, private OrgMailer $mailer) {}

    public function render(string $text, Lead $lead, ?User $sender): string
    {
        $lead->loadMissing(['owner', 'organization']);
        $values = [
            'first_name' => $lead->first_name,
            'last_name' => $lead->last_name,
            'name' => $lead->full_name,
            'company' => $lead->company ?: 'your company',
            'job_title' => $lead->job_title,
            'email' => $lead->email,
            'owner' => ['name' => $lead->owner?->name, 'email' => $lead->owner?->email],
            'sender' => ['name' => $sender?->name],
            'organization' => ['name' => $lead->organization?->name],
            'unsubscribe_url' => $lead->id ? $lead->unsubscribeUrl() : '',
        ];
        $flat = Arr::dot($values);

        return preg_replace_callback('/\{([a-z_.]+)\}/', fn ($m) => (string) ($flat[$m[1]] ?? ''), $text);
    }

    public function send(Lead $lead, string $subject, string $body, ?User $sender, ?EmailTemplate $template = null): void
    {
        if (! $lead->email) {
            throw ValidationException::withMessages(['email' => 'This lead has no email address.']);
        }
        if (! $lead->canContact('email')) {
            throw ValidationException::withMessages(['email' => "{$lead->full_name} has opted out of email."]);
        }

        $subject = $this->render($subject, $lead, $sender);
        $body = $this->render($body, $lead, $sender);
        // Every email carries a one-click way to opt out.
        if (! str_contains($body, $lead->unsubscribeUrl())) {
            $body .= "\n\n—\nDon't want these emails? Unsubscribe: ".$lead->unsubscribeUrl();
        }

        $provider = $this->mailer->send($lead->organization_id, $lead->email, $lead->full_name, $subject, $body, $sender ? [$sender->email, $sender->name] : null);

        $this->activities->record($lead, 'email', $subject, [
            'description' => $body,
            'direction' => 'outbound',
            'outcome' => 'Sent',
            'meta' => array_filter(['template_id' => $template?->id, 'provider' => $provider]),
        ]);

        $lead->forceFill(['last_contacted_at' => now()])->saveQuietly();
        $template?->increment('usage_count');
    }
}
