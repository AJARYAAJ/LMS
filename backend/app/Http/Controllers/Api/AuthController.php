<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\OrganizationProvisioner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function register(Request $request, OrganizationProvisioner $provisioner): JsonResponse
    {
        $data = $request->validate([
            'organization_name' => ['required', 'string', 'max:120'],
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:190', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::min(8)],
            'industry' => ['nullable', 'string', 'max:120'],
            'currency' => ['nullable', 'string', 'size:3'],
        ]);

        $user = $provisioner->provision(
            ['name' => $data['organization_name'], 'industry' => $data['industry'] ?? null, 'currency' => strtoupper($data['currency'] ?? 'USD')],
            ['name' => $data['name'], 'email' => strtolower($data['email']), 'password' => $data['password']],
        );

        return response()->json($this->tokenResponse($user, $request), 201);
    }

    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::withoutGlobalScopes()->where('email', strtolower($data['email']))->first();

        if (! $user || ! Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages(['email' => 'These credentials do not match our records.']);
        }

        if (! $user->is_active) {
            throw ValidationException::withMessages(['email' => 'Your account has been deactivated.']);
        }

        return response()->json($this->tokenResponse($user, $request));
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()?->delete();

        return response()->json(['message' => 'Logged out.']);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json($this->profile($request->user()));
    }

    public function updateProfile(Request $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'email' => ['sometimes', 'email', 'max:190', 'unique:users,email,'.$user->id],
            'phone' => ['nullable', 'string', 'max:40'],
            'job_title' => ['nullable', 'string', 'max:120'],
            'avatar_color' => ['nullable', 'string', 'max:16'],
            'preferences' => ['nullable', 'array'],
        ]);

        $user->update($data);

        return response()->json($this->profile($user->fresh()));
    }

    public function updatePassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'current_password:sanctum'],
            'password' => ['required', 'confirmed', Password::min(8)],
        ]);

        $request->user()->update(['password' => $data['password']]);

        return response()->json(['message' => 'Password updated.']);
    }

    private function tokenResponse(User $user, Request $request): array
    {
        $user->forceFill(['last_login_at' => now()])->saveQuietly();
        $token = $user->createToken(substr($request->userAgent() ?? 'spa', 0, 100))->plainTextToken;

        return ['token' => $token, 'user' => $this->profile($user)];
    }

    private function profile(User $user): array
    {
        $user->loadMissing(['organization', 'teams:id,name,color']);

        return array_merge($user->toArray(), [
            'permissions' => [
                'write' => $user->canWrite(),
                'manage_settings' => $user->isAdmin(),
                'manage_team' => $user->hasRole(User::ADMIN, User::MANAGER),
                'view_all_leads' => $user->hasRole(User::ADMIN, User::VIEWER),
            ],
        ]);
    }
}
