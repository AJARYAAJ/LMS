<?php

namespace App\Integrations;

use App\Models\Integration;

/**
 * Resolves the connected vendor for a capability. Organization connections
 * win; environment variables remain as a server-wide fallback.
 */
class IntegrationManager
{
    public function active(int $organizationId, string $category): ?Integration
    {
        return Integration::withoutGlobalScopes()
            ->where('organization_id', $organizationId)
            ->where('category', $category)
            ->where('is_active', true)
            ->orderByRaw("case when provider = 'simulator' then 1 else 0 end") // prefer real vendors
            ->orderBy('id')
            ->first();
    }

    public function provider(int $organizationId, string $provider): ?Integration
    {
        return Integration::withoutGlobalScopes()->where('organization_id', $organizationId)->where('provider', $provider)->where('is_active', true)->first();
    }

    /** Anthropic credentials: organization connection or ANTHROPIC_API_KEY. */
    public function anthropic(int $organizationId): ?array
    {
        $i = $this->provider($organizationId, 'anthropic');
        $key = $i?->setting('api_key') ?: config('services.anthropic.key');

        return $key ? ['key' => $key, 'model' => $i?->setting('model') ?: config('services.anthropic.model')] : null;
    }
}
