<?php

namespace App\Services;

use App\Models\Lead;
use App\Support\Phone;
use Illuminate\Support\Collection;

class DuplicateDetector
{
    /**
     * Find existing leads in the current organization that share the email
     * address or (normalized) phone number.
     */
    public function find(?string $email, ?string $phone, ?int $excludeId = null): Collection
    {
        $email = $email ? strtolower(trim($email)) : null;
        $phoneDigits = $phone ? preg_replace('/\D+/', '', $phone) : null;

        if (! $email && (! $phoneDigits || strlen($phoneDigits) < 6)) {
            return collect();
        }

        return Lead::query()
            ->when($excludeId, fn ($q) => $q->whereKeyNot($excludeId))
            ->where(function ($q) use ($email, $phoneDigits) {
                if ($email) {
                    $q->orWhereRaw('lower(email) = ?', [$email]);
                }
                if ($phoneDigits && strlen($phoneDigits) >= 6) {
                    Phone::whereMatches($q, $phoneDigits, boolean: 'or');
                }
            })
            ->with(['status:id,name,color', 'owner:id,name'])
            ->limit(10)
            ->get(['id', 'first_name', 'last_name', 'email', 'phone', 'company', 'lead_status_id', 'owner_id', 'created_at']);
    }
}
