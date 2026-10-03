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
        'transcription' => 'Call transcription',
        'chat' => 'Team chat alerts',
        'sso' => 'Single sign-on',
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
                'category' => 'ai', 'name' => 'Anthropic Claude', 'description' => 'Powers AI lead briefs, call analysis and plain-English reports.',
                'fields' => [
                    ['key' => 'api_key', 'label' => 'API key', 'required' => true, 'secret' => true],
                    ['key' => 'model', 'label' => 'Model', 'placeholder' => 'claude-opus-5'],
                ],
            ],
            'deepgram' => [
                'category' => 'transcription', 'name' => 'Deepgram', 'description' => 'Turns uploaded call recordings into speaker-separated transcripts for call analysis.',
                'fields' => [
                    ['key' => 'api_key', 'label' => 'API key', 'required' => true, 'secret' => true],
                    ['key' => 'model', 'label' => 'Model', 'placeholder' => 'nova-3'],
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
            'google_sso' => [
                'category' => 'sso', 'name' => 'Google Workspace', 'description' => 'People with your domain sign in with their Google account. Create an OAuth client (Web application) in Google Cloud and add the redirect URL below.',
                'fields' => [
                    ['key' => 'client_id', 'label' => 'Client ID', 'required' => true],
                    ['key' => 'client_secret', 'label' => 'Client secret', 'required' => true, 'secret' => true],
                    ['key' => 'domains', 'label' => 'Email domains', 'required' => true, 'placeholder' => 'yourcompany.com, yourcompany.co.uk'],
                    ['key' => 'default_role', 'label' => 'Role for new people', 'options' => ['sales_rep', 'viewer', 'manager']],
                    ['key' => 'enforce', 'label' => 'Require SSO (admins can still use a password)', 'options' => ['no', 'yes']],
                ],
                'redirect' => 'sso/callback',
            ],
            'microsoft_sso' => [
                'category' => 'sso', 'name' => 'Microsoft Entra ID', 'description' => 'Sign in with Microsoft 365 / Entra ID. Register an app, add the redirect URL below as a Web redirect, and create a client secret.',
                'fields' => [
                    ['key' => 'client_id', 'label' => 'Application (client) ID', 'required' => true],
                    ['key' => 'client_secret', 'label' => 'Client secret', 'required' => true, 'secret' => true],
                    ['key' => 'tenant', 'label' => 'Directory (tenant) ID', 'placeholder' => 'organizations'],
                    ['key' => 'domains', 'label' => 'Email domains', 'required' => true, 'placeholder' => 'yourcompany.com, yourcompany.co.uk'],
                    ['key' => 'default_role', 'label' => 'Role for new people', 'options' => ['sales_rep', 'viewer', 'manager']],
                    ['key' => 'enforce', 'label' => 'Require SSO (admins can still use a password)', 'options' => ['no', 'yes']],
                ],
                'redirect' => 'sso/callback',
            ],
            'oidc_sso' => [
                'category' => 'sso', 'name' => 'OpenID Connect', 'description' => 'Okta, Auth0, OneLogin, Keycloak, JumpCloud or any OpenID Connect identity provider.',
                'fields' => [
                    ['key' => 'issuer', 'label' => 'Issuer URL', 'required' => true, 'placeholder' => 'https://yourcompany.okta.com'],
                    ['key' => 'client_id', 'label' => 'Client ID', 'required' => true],
                    ['key' => 'client_secret', 'label' => 'Client secret', 'required' => true, 'secret' => true],
                    ['key' => 'domains', 'label' => 'Email domains', 'required' => true, 'placeholder' => 'yourcompany.com, yourcompany.co.uk'],
                    ['key' => 'default_role', 'label' => 'Role for new people', 'options' => ['sales_rep', 'viewer', 'manager']],
                    ['key' => 'enforce', 'label' => 'Require SSO (admins can still use a password)', 'options' => ['no', 'yes']],
                ],
                'redirect' => 'sso/callback',
            ],
        ];
    }

    public static function get(string $provider): ?array
    {
        return self::providers()[$provider] ?? null;
    }
}
