<?php

namespace App\Http\Controllers\Api\Settings;

use Anthropic\Client;
use App\Http\Controllers\Controller;
use App\Integrations\Catalog;
use App\Models\Integration;
use App\Services\AuditLogger;
use App\Services\ChatAlerts;
use App\Services\OrgMailer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Integrations hub: connect vendors with encrypted, per-organization
 * credentials. Secrets are write-only — the API only says whether one is set.
 */
class IntegrationController extends Controller
{
    public function index(): JsonResponse
    {
        $connected = Integration::orderBy('id')->get()->keyBy('provider');

        return response()->json(['data' => [
            'categories' => Catalog::CATEGORIES,
            'providers' => collect(Catalog::providers())->map(function (array $def, string $key) use ($connected) {
                $i = $connected[$key] ?? null;

                return [
                    'key' => $key,
                    ...collect($def)->except('inbound')->all(),
                    'connection' => $i ? $this->present($i, $def) : null,
                ];
            })->values(),
        ]]);
    }

    public function store(Request $request, AuditLogger $audit): JsonResponse
    {
        $data = $request->validate([
            'provider' => ['required', Rule::in(array_keys(Catalog::providers()))],
            'config' => ['nullable', 'array'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
        $def = Catalog::get($data['provider']);
        $existing = Integration::where('provider', $data['provider'])->first();

        // Blank secret fields keep their stored value so admins never re-type them.
        $config = [];
        foreach ($def['fields'] as $field) {
            $value = trim((string) ($data['config'][$field['key']] ?? ''));
            if ($value === '' && ! empty($field['secret']) && $existing) {
                $value = (string) $existing->setting($field['key'], '');
            }
            if ($value === '' && ! empty($field['required'])) {
                throw ValidationException::withMessages(["config.{$field['key']}" => "{$field['label']} is required."]);
            }
            if ($value !== '') {
                $config[$field['key']] = $value;
            }
        }
        if (isset($config['webhook_url']) && ! filter_var($config['webhook_url'], FILTER_VALIDATE_URL)) {
            throw ValidationException::withMessages(['config.webhook_url' => 'Enter a valid URL.']);
        }

        $integration = Integration::updateOrCreate(['provider' => $data['provider']], [
            'category' => $def['category'],
            'config' => $config,
            'is_active' => $data['is_active'] ?? true,
            'status' => 'connected',
            'last_error' => null,
        ]);
        $audit->log($existing ? 'integration.updated' : 'integration.connected', $integration, [], ['provider' => $integration->provider]);

        return response()->json(['data' => $this->present($integration, $def)], $existing ? 200 : 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $integration = Integration::findOrFail($id);
        $integration->update($request->validate(['is_active' => ['required', 'boolean']]));

        return response()->json(['data' => $this->present($integration, Catalog::get($integration->provider))]);
    }

    public function destroy(int $id, AuditLogger $audit): JsonResponse
    {
        $integration = Integration::findOrFail($id);
        $integration->delete();
        $audit->log('integration.disconnected', $integration, ['provider' => $integration->provider]);

        return response()->json(null, 204);
    }

    /** Verify credentials with the vendor (best effort; sends nothing to leads). */
    public function test(Request $request, int $id, OrgMailer $mailer, ChatAlerts $chat): JsonResponse
    {
        $i = Integration::findOrFail($id);
        $user = $request->user();

        try {
            $message = match ($i->provider) {
                'smtp', 'sendgrid' => (function () use ($mailer, $user) {
                    $mailer->send($user->organization_id, $user->email, $user->name, 'LeadFlow test email', "Your email integration works.\n\n— LeadFlow");

                    return "Test email sent to {$user->email}.";
                })(),
                'twilio' => $this->check(Http::withBasicAuth($i->setting('account_sid'), $i->setting('auth_token'))->timeout(10)
                    ->get("https://api.twilio.com/2010-04-01/Accounts/{$i->setting('account_sid')}.json"), 'Twilio account verified.'),
                'meta_whatsapp' => $this->check(Http::withToken($i->setting('access_token'))->timeout(10)
                    ->get('https://graph.facebook.com/v21.0/'.$i->setting('phone_number_id')), 'WhatsApp number verified.'),
                'vapi' => $this->check(Http::withToken($i->setting('api_key'))->timeout(10)
                    ->get('https://api.vapi.ai/phone-number/'.$i->setting('phone_number_id')), 'Vapi phone number verified.'),
                'retell' => $this->check(Http::withToken($i->setting('api_key'))->timeout(10)
                    ->get('https://api.retellai.com/get-agent/'.$i->setting('agent_id')), 'Retell agent verified.'),
                'bland' => $i->setting('api_key') ? 'Bland API key saved. It is verified on the first call.' : throw new \RuntimeException('Missing API key.'),
                'simulator' => 'The simulator is ready — no credentials needed.',
                'anthropic' => (function () use ($i) {
                    (new Client(apiKey: $i->setting('api_key'), requestOptions: ['timeout' => 15, 'maxRetries' => 0]))
                        ->models->retrieve($i->setting('model') ?: config('services.anthropic.model'));

                    return 'Anthropic key verified.';
                })(),
                'slack', 'teams' => $chat->post($i->organization_id, 'test', 'LeadFlow is connected', 'Alerts for your team will appear here.') > 0
                    ? 'Test message posted.' : throw new \RuntimeException($i->fresh()->last_error ?? 'Could not post to the channel.'),
                default => 'Saved.',
            };
            $i->forceFill(['status' => 'connected', 'last_error' => null, 'last_tested_at' => now()])->save();

            return response()->json(['message' => $message, 'data' => $this->present($i, Catalog::get($i->provider))]);
        } catch (Throwable $e) {
            $i->forceFill(['status' => 'error', 'last_error' => mb_substr($e->getMessage(), 0, 500), 'last_tested_at' => now()])->save();

            return response()->json(['message' => 'Test failed: '.mb_substr($e->getMessage(), 0, 300), 'data' => $this->present($i, Catalog::get($i->provider))], 422);
        }
    }

    private function check($response, string $ok): string
    {
        if ($response->failed()) {
            throw new \RuntimeException('Vendor responded '.$response->status().($response->json('message') ? ': '.(is_string($response->json('message')) ? $response->json('message') : json_encode($response->json('message'))) : ''));
        }

        return $ok;
    }

    private function present(Integration $i, array $def): array
    {
        return [
            'id' => $i->id,
            'is_active' => $i->is_active,
            'status' => $i->status,
            'last_error' => $i->last_error,
            'last_tested_at' => $i->last_tested_at,
            'values' => collect($def['fields'])->mapWithKeys(fn ($f) => [$f['key'] => ! empty($f['secret']) ? null : $i->setting($f['key'])]),
            'secrets_set' => collect($def['fields'])->filter(fn ($f) => ! empty($f['secret']))->mapWithKeys(fn ($f) => [$f['key'] => filled($i->setting($f['key']))]),
            'inbound_url' => isset($def['inbound']) ? url("/api/v1/webhooks/{$def['inbound']}/".Integration::withoutGlobalScopes()->whereKey($i->id)->value('inbound_token')) : null,
        ];
    }
}
