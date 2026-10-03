<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'description', 'trigger', 'conditions', 'actions', 'is_active', 'run_count', 'last_run_at'])]
class AutomationRule extends Model
{
    use BelongsToOrganization;

    protected function casts(): array
    {
        return [
            'conditions' => 'array',
            'actions' => 'array',
            'is_active' => 'boolean',
            'run_count' => 'integer',
            'last_run_at' => 'datetime',
        ];
    }

    public function executions(): HasMany
    {
        return $this->hasMany(AutomationExecution::class);
    }

    public const TRIGGERS = ['lead.created', 'lead.updated', 'lead.status_changed', 'lead.assigned', 'lead.converted', 'lead.call_completed'];

    public const ACTIONS = ['create_task', 'notify_owner', 'notify_user', 'update_field', 'add_tag', 'change_status', 'assign_user', 'add_note'];
}
