<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['oauth_app_id', 'user_id', 'access_token_id', 'token_hash', 'scopes', 'expires_at', 'revoked_at'])]
class OAuthRefreshToken extends Model
{
    protected $table = 'oauth_refresh_tokens';

    protected function casts(): array
    {
        return ['scopes' => 'array', 'expires_at' => 'datetime', 'revoked_at' => 'datetime'];
    }

    public function app(): BelongsTo
    {
        return $this->belongsTo(OAuthApp::class, 'oauth_app_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
