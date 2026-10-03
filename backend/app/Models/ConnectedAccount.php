<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['organization_id', 'user_id', 'provider', 'email', 'access_token', 'refresh_token', 'expires_at', 'sync_mail', 'sync_calendar', 'last_synced_at', 'last_error'])]
#[Hidden(['access_token', 'refresh_token'])]
class ConnectedAccount extends Model
{
    use BelongsToOrganization;

    public const PROVIDERS = ['google' => 'Google (Gmail & Calendar)', 'microsoft' => 'Microsoft (Outlook & Calendar)'];

    protected function casts(): array
    {
        return [
            'access_token' => 'encrypted',
            'refresh_token' => 'encrypted',
            'expires_at' => 'datetime',
            'last_synced_at' => 'datetime',
            'sync_mail' => 'boolean',
            'sync_calendar' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** The connection a person's emails should go out through, if any. */
    public static function mailFor(?User $user): ?self
    {
        return $user ? static::withoutGlobalScopes()->where('user_id', $user->id)->where('sync_mail', true)->whereNull('last_error')->first() : null;
    }

    public static function calendarFor(int $userId): ?self
    {
        return static::withoutGlobalScopes()->where('user_id', $userId)->where('sync_calendar', true)->first();
    }
}
