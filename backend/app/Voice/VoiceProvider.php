<?php

namespace App\Voice;

use App\Models\AiAgent;
use App\Models\Call;
use App\Models\Integration;
use App\Models\Lead;

interface VoiceProvider
{
    /**
     * Place the call with the vendor.
     *
     * @return array{provider_call_id: ?string, status: string}
     */
    public function start(Call $call, AiAgent $agent, Lead $lead, Integration $integration, string $webhookUrl): array;

    /**
     * Normalise a vendor webhook into LeadFlow's call shape, or null to ignore it.
     *
     * @return array{provider_call_id: ?string, call_id: ?int, status: string, final: bool, duration_seconds?: ?int, recording_url?: ?string, transcript?: list<array{role: string, text: string}>, summary?: ?string, extracted?: array, cost?: ?float}|null
     */
    public function parse(array $payload): ?array;
}
