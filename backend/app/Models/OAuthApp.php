<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['organization_id', 'created_by', 'name', 'client_id', 'secret_hash', 'redirect_uris'])]
#[Hidden(['secret_hash'])]
class OAuthApp extends Model
{
    use BelongsToOrganization;

    protected $table = 'oauth_apps';

    /** What an app can ask for. */
    public const SCOPES = [
        'read' => 'See your leads, contacts, deals, tasks, activities and reports',
        'write' => 'Create and update records on your behalf',
    ];

    protected function casts(): array
    {
        return ['redirect_uris' => 'array'];
    }

    public function refreshTokens(): HasMany
    {
        return $this->hasMany(OAuthRefreshToken::class);
    }

    public function isConfidential(): bool
    {
        return $this->secret_hash !== null;
    }

    public function tokenName(): string
    {
        return "oauth:{$this->client_id}";
    }
}
