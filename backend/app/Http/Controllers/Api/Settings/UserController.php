<?php

namespace App\Http\Controllers\Api\Settings;

use App\Http\Controllers\Controller;
use App\Models\Lead;
use App\Models\Task;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\Rules;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class UserController extends Controller
{
    public function __construct(private AuditLogger $audit) {}

    public function index(Request $request): JsonResponse
    {
        $users = User::with('teams:id,name,color')
            ->withCount([
                'leads as open_leads_count' => fn ($q) => $q->whereNull('converted_at'),
            ])
            ->when($request->query('search'), fn ($q, $s) => $q->where(fn ($q) => $q->whereLike('name', "%{$s}%")->orWhereLike('email', "%{$s}%")))
            ->orderBy('name')
            ->get();

        return response()->json(['data' => $users]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:190', 'unique:users,email'],
            'password' => ['required', Password::min(8)],
            'role' => ['required', Rule::in(User::ROLES)],
            'phone' => ['nullable', 'string', 'max:40'],
            'job_title' => ['nullable', 'string', 'max:120'],
            'team_ids' => ['sometimes', 'array'],
            'team_ids.*' => ['integer', Rules::exists('teams')],
        ]);

        $user = User::create([...collect($data)->except('team_ids')->all(), 'email' => strtolower($data['email']), 'avatar_color' => $this->color()]);
        $user->teams()->sync($data['team_ids'] ?? []);
        $this->audit->log('user.created', $user, [], $user->only(['name', 'email', 'role']));

        return response()->json(['data' => $user->load('teams:id,name,color')], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $user = User::findOrFail($id);
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'email' => ['sometimes', 'email', 'max:190', 'unique:users,email,'.$user->id],
            'password' => ['sometimes', 'nullable', Password::min(8)],
            'role' => ['sometimes', Rule::in(User::ROLES)],
            'is_active' => ['sometimes', 'boolean'],
            'phone' => ['nullable', 'string', 'max:40'],
            'job_title' => ['nullable', 'string', 'max:120'],
            'team_ids' => ['sometimes', 'array'],
            'team_ids.*' => ['integer', Rules::exists('teams')],
        ]);

        $this->guardLastAdmin($user, $data);

        if (empty($data['password'])) {
            unset($data['password']);
        }

        $original = $user->getOriginal();
        $user->update(collect($data)->except('team_ids')->all());
        if (array_key_exists('team_ids', $data)) {
            $user->teams()->sync($data['team_ids']);
        }
        if (($data['is_active'] ?? true) === false) {
            $user->tokens()->delete();
        }
        $this->audit->logChanges('user.updated', $user, $original);

        return response()->json(['data' => $user->fresh()->load('teams:id,name,color')]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $user = User::findOrFail($id);

        if ($user->id === $request->user()->id) {
            throw ValidationException::withMessages(['user' => 'You cannot delete your own account.']);
        }
        $this->guardLastAdmin($user, ['role' => null]);

        // Return the user's open work to the unassigned queue before removal.
        Lead::where('owner_id', $user->id)->update(['owner_id' => null]);
        Task::where('assigned_to', $user->id)->whereNull('completed_at')->update(['assigned_to' => null]);
        $user->tokens()->delete();
        $user->delete();
        $this->audit->log('user.deleted', $user, $user->only(['name', 'email', 'role']));

        return response()->json(null, 204);
    }

    private function guardLastAdmin(User $user, array $data): void
    {
        $demoting = array_key_exists('role', $data) && $data['role'] !== User::ADMIN;
        $deactivating = ($data['is_active'] ?? true) === false;

        if ($user->isAdmin() && ($demoting || $deactivating)
            && User::where('role', User::ADMIN)->where('is_active', true)->count() <= 1) {
            throw ValidationException::withMessages(['role' => 'An organization needs at least one active admin.']);
        }
    }

    private function color(): string
    {
        $palette = ['#6366f1', '#0ea5e9', '#10b981', '#f59e0b', '#ec4899', '#8b5cf6', '#14b8a6', '#ef4444'];

        return $palette[array_rand($palette)];
    }
}
