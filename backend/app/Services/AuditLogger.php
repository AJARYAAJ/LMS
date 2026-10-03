<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Support\Tenant;
use Illuminate\Database\Eloquent\Model;

class AuditLogger
{
    private const HIDDEN = ['password', 'remember_token', 'secret', 'key_hash', 'updated_at', 'created_at'];

    public function log(string $event, ?Model $model = null, array $old = [], array $new = []): void
    {
        $organizationId = $model?->organization_id ?? Tenant::id();

        if (! $organizationId) {
            return;
        }

        AuditLog::create([
            'organization_id' => $organizationId,
            'user_id' => auth()->id(),
            'auditable_type' => $model?->getMorphClass(),
            'auditable_id' => $model?->getKey(),
            'event' => $event,
            'old_values' => $old ? array_diff_key($old, array_flip(self::HIDDEN)) : null,
            'new_values' => $new ? array_diff_key($new, array_flip(self::HIDDEN)) : null,
            'ip_address' => request()?->ip(),
            'created_at' => now(),
        ]);
    }

    /**
     * Log only the attributes that actually changed on a saved model.
     */
    public function logChanges(string $event, Model $model, array $original): void
    {
        $changes = array_diff_key($model->getChanges(), array_flip(self::HIDDEN));

        if (! $changes) {
            return;
        }

        $this->log($event, $model, array_intersect_key($original, $changes), $changes);
    }
}
