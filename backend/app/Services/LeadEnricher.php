<?php

namespace App\Services;

use App\Models\Lead;
use Illuminate\Support\Str;

/**
 * Lightweight, offline enrichment: derives company and website from a
 * business email domain. Designed as the seam where a third-party
 * enrichment provider (Clearbit, Apollo, ...) can be plugged in later.
 */
class LeadEnricher
{
    /**
     * @return array<string, string> the attributes that were filled in
     */
    public function enrich(Lead $lead): array
    {
        $email = Str::lower((string) $lead->email);

        if (! str_contains($email, '@')) {
            return [];
        }

        $domain = Str::after($email, '@');

        if (in_array($domain, ConditionEvaluator::FREE_EMAIL_DOMAINS, true)) {
            return [];
        }

        $filled = [];

        if (! $lead->website) {
            $filled['website'] = $domain;
        }

        if (! $lead->company) {
            $name = Str::before($domain, '.');
            $filled['company'] = Str::of($name)->replace(['-', '_'], ' ')->title()->toString();
        }

        if ($filled) {
            $lead->forceFill($filled)->saveQuietly();
        }

        return $filled;
    }
}
