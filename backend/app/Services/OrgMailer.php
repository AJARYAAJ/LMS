<?php

namespace App\Services;

use App\Integrations\IntegrationManager;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use RuntimeException;

/**
 * Sends email through the organization's connected vendor (SMTP or SendGrid),
 * falling back to the application's default mailer.
 *
 * @return string the provider used
 */
class OrgMailer
{
    public function __construct(private IntegrationManager $integrations) {}

    public function send(int $organizationId, string $to, ?string $toName, string $subject, string $text, ?array $replyTo = null): string
    {
        $integration = $this->integrations->active($organizationId, 'email');

        if ($integration?->provider === 'sendgrid') {
            $response = Http::withToken($integration->setting('api_key'))->timeout(15)->post('https://api.sendgrid.com/v3/mail/send', array_filter([
                'personalizations' => [['to' => [array_filter(['email' => $to, 'name' => $toName])]]],
                'from' => array_filter(['email' => $integration->setting('from_address'), 'name' => $integration->setting('from_name')]),
                'reply_to' => $replyTo ? ['email' => $replyTo[0], 'name' => $replyTo[1] ?? null] : null,
                'subject' => $subject,
                'content' => [['type' => 'text/plain', 'value' => $text]],
            ]));
            if ($response->failed()) {
                throw new RuntimeException('SendGrid rejected the email: '.($response->json('errors.0.message') ?? $response->status()));
            }

            return 'sendgrid';
        }

        $mailer = 'default';
        if ($integration?->provider === 'smtp') {
            $mailer = "org_smtp_{$organizationId}";
            $encryption = $integration->setting('encryption', 'tls');
            config(["mail.mailers.{$mailer}" => [
                'transport' => 'smtp',
                'host' => $integration->setting('host'),
                'port' => (int) $integration->setting('port', 587),
                'username' => $integration->setting('username'),
                'password' => $integration->setting('password'),
                'scheme' => $encryption === 'ssl' ? 'smtps' : null,
                'timeout' => 15,
            ]]);
        }

        Mail::mailer($mailer === 'default' ? null : $mailer)->raw($text, function ($m) use ($to, $toName, $subject, $replyTo, $integration) {
            $m->to($to, $toName)->subject($subject);
            if ($integration?->provider === 'smtp') {
                $m->from($integration->setting('from_address'), $integration->setting('from_name'));
            }
            if ($replyTo) {
                $m->replyTo($replyTo[0], $replyTo[1] ?? null);
            }
        });

        return $mailer === 'default' ? config('mail.default') : 'smtp';
    }
}
