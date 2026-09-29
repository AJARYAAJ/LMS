<?php

namespace App\Integrations;

/**
 * Every vendor LeadFlow can connect to, with the credentials each needs.
 * Secrets are stored encrypted and never returned to the browser.
 */
class Catalog
{
    public const CATEGORIES = [
        'email' => 'Email delivery',
        'messaging' => 'SMS & WhatsApp',
        'voice' => 'AI voice calling',
        'ai' => 'AI assistant',
        'chat' => 'Team chat alerts',
    ];

    public static function providers(): array
    {
        return [
            'smtp' => [
                'category' => 'email', 'name' => 'SMTP', 'description' => 'Any SMTP server — Google Workspace, Microsoft 365, Amazon SES, Mailgun, Postmark…',
                'fields' => [
                    ['key' => 'host', 'label' => 'Host', 'required' => true, 'placeholder' => 'smtp.example.com'],
                    ['key' => 'port', 'label' => 'Port', 'required' => true, 'placeholder' => '587'],
                    ['key' => 'username', 'label' => 'Username'],
                    ['key' => 'password', 'label' => 'Password', 'secret' => true],
                    ['key' => 'encryption', 'label' => 'Encryption', 'options' => ['tls', 'ssl', 'none']],
                    ['key' => 'from_address', 'label' => 'From address', 'required' => true, 'placeholder' => 'sales@yourcompany.com'],
                    ['key' => 'from_name', 'label' => 'From name'],
                ],
            ],
            'sendgrid' => [
                'category' => 'email', 'name' => 'SendGrid', 'description' => 'Twilio SendGrid email API.',
                'fields' => [
                    ['key' => 'api_key', 'label' => 'API key', 'required' => true, 'secret' => true],
                    ['key' => 'from_address', 'label' => 'Verified sender', 'required' => true],
                    ['key' => 'from_name', 'label' => 'From name'],
                ],
            ],
            'twilio' => [
                'category' => 'messaging', 'name' => 'Twilio', 'description' => 'SMS and WhatsApp through Twilio. Replies flow back via the inbound webhook.',
                'fields' => [
                    ['key' => 'account_sid', 'label' => 'Account SID', 'required' => true],
                    ['key' => 'auth_token', 'label' => 'Auth token', 'required' => true, 'secret' => true],
                    ['key' => 'from', 'label' => 'SMS number', 'placeholder' => '+15551234567'],
                    ['key' => 'whatsapp_from', 'label' => 'WhatsApp sender', 'placeholder' => 'whatsapp:+14155238886'],
                ],
                'inbound' => 'messaging/twilio',
            ],
            'meta_whatsapp' => [
                'category' => 'messaging', 'name' => 'WhatsApp Cloud API', 'description' => 'Meta’s official WhatsApp Business Cloud API (WhatsApp only).',
                'fields' => [
                    ['key' => 'phone_number_id', 'label' => 'Phone number ID', 'required' => true],
                    ['key' => 'access_token', 'label' => 'Access token', 'required' => true, 'secret' => true],
                ],
            ],
            'vapi' => [
                'category' => 'voice', 'name' => 'Vapi', 'description' => 'Realistic AI phone agents. Set the server URL of your Vapi phone number to the webhook below.',
                'fields' => [
                    ['key' => 'api_key', 'label' => 'Private API key', 'required' => true, 'secret' => true],
                    ['key' => 'phone_number_id', 'label' => 'Phone number ID', 'required' => true],
                    ['key' => 'assistant_id', 'label' => 'Assistant ID (optional — otherwise LeadFlow builds the assistant from your AI agent)'],
                ],
                'inbound' => 'voice/vapi',
            ],
            'retell' => [
                'category' => 'voice', 'name' => 'Retell AI', 'description' => 'Low-latency voice agents. Point the agent webhook to the URL below.',
                'fields' => [
                    ['key' => 'api_key', 'label' => 'API key', 'required' => true, 'secret' => true],
                    ['key' => 'from_number', 'label' => 'From number', 'required' => true, 'placeholder' => '+15551234567'],
                    ['key' => 'agent_id', 'label' => 'Agent ID', 'required' => true],
                ],
                'inbound' => 'voice/retell',
            ],
            'bland' => [
                'category' => 'voice', 'name' => 'Bland AI', 'description' => 'AI phone calls from a single API call.',
                'fields' => [
                    ['key' => 'api_key', 'label' => 'API key', 'required' => true, 'secret' => true],
                    ['key' => 'from', 'label' => 'From number (optional)'],
                ],
                'inbound' => 'voice/bland',
            ],
            'simulator' => [
                'category' => 'voice', 'name' => 'Call simulator', 'description' => 'Built-in demo provider: simulates realistic AI calls without dialling anyone. Great for trying the flow.',
                'fields' => [],
            ],
            'anthropic' => [
                'category' => 'ai', 'name' => 'Anthropic Claude', 'description' => 'Powers AI lead briefs.',
                'fields' => [
                    ['key' => 'api_key', 'label' => 'API key', 'required' => true, 'secret' => true],
                    ['key' => 'model', 'label' => 'Model', 'placeholder' => 'claude-opus-5'],
                ],
            ],
            'slack' => [
                'category' => 'chat', 'name' => 'Slack', 'description' => 'Post alerts to a Slack channel with an incoming webhook.',
                'fields' => [['key' => 'webhook_url', 'label' => 'Incoming webhook URL', 'required' => true, 'secret' => true, 'placeholder' => 'https://hooks.slack.com/services/…']],
            ],
            'teams' => [
                'category' => 'chat', 'name' => 'Microsoft Teams', 'description' => 'Post alerts to a Teams channel with an incoming webhook / workflow URL.',
                'fields' => [['key' => 'webhook_url', 'label' => 'Webhook URL', 'required' => true, 'secret' => true]],
            ],
        ];
    }

    public static function get(string $provider): ?array
    {
        return self::providers()[$provider] ?? null;
    }
}
