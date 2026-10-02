<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Notifications\AppNotification;
use App\Security\Totp;
use App\Services\OrganizationProvisioner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
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
        if (SsoController::enforced($user)) {
            throw ValidationException::withMessages(['email' => 'Your organization signs in with single sign-on. Use “Sign in with SSO”.']);
        }

        // Two-step login: the password alone only earns a short-lived challenge.
        if ($user->hasTwoFactor()) {
            return response()->json([
                'two_factor_required' => true,
                'challenge' => Crypt::encryptString(json_encode(['uid' => $user->id, 'exp' => now()->addMinutes(5)->timestamp])),
            ]);
        }

        return response()->json($this->tokenResponse($user, $request));
    }

    /** Second step: an authenticator code (or a one-time recovery code) exchanges the challenge for a token. */
    public function twoFactorChallenge(Request $request): JsonResponse
    {
        $data = $request->validate([
            'challenge' => ['required', 'string'],
            'code' => ['required_without:recovery_code', 'nullable', 'string'],
            'recovery_code' => ['required_without:code', 'nullable', 'string'],
        ]);
        try {
            $payload = json_decode(Crypt::decryptString($data['challenge']), true, flags: JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            throw ValidationException::withMessages(['code' => 'This sign-in attempt expired. Please sign in again.']);
        }
        abort_if(($payload['exp'] ?? 0) < now()->timestamp, 422, 'This sign-in attempt expired. Please sign in again.');
        $user = User::withoutGlobalScopes()->where('is_active', true)->findOrFail($payload['uid'] ?? 0);
        abort_unless($user->hasTwoFactor(), 422, 'Two-step login is not enabled.');

        if (! empty($data['code'])) {
            if (! Totp::verify($user->two_factor_secret, $data['code'])) {
                throw ValidationException::withMessages(['code' => 'That code didn’t match. Check your authenticator app and try again.']);
            }
        } else {
            $codes = $user->two_factor_recovery_codes ?? [];
            $given = strtolower(trim($data['recovery_code']));
            $match = collect($codes)->first(fn ($c) => hash_equals($c, $given));
            if (! $match) {
                throw ValidationException::withMessages(['recovery_code' => 'That recovery code isn’t valid or was already used.']);
            }
            $user->forceFill(['two_factor_recovery_codes' => array_values(array_diff($codes, [$match]))])->save();
        }

        return response()->json($this->tokenResponse($user, $request));
    }

    /** Finish single sign-on: the one-time code from the SSO redirect becomes an API token. */
    public function ssoExchange(Request $request): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'size:48']]);
        $userId = Cache::pull("sso_login:{$data['code']}");
        $user = $userId ? User::withoutGlobalScopes()->where('is_active', true)->find($userId) : null;
        if (! $user) {
            throw ValidationException::withMessages(['code' => 'This sign-in link expired. Please try again.']);
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
        // A sign-in from a browser or device we haven't seen before is worth knowing about.
        $prefs = $user->preferences ?? [];
        $device = substr(hash('sha256', (string) $request->userAgent()), 0, 16);
        $known = $prefs['devices'] ?? null;
        if (is_array($known) && ! in_array($device, $known, true)) {
            $user->notify(new AppNotification('New sign-in to your account', 'From '.self::describeAgent((string) $request->userAgent()).' at '.now()->format('M j, H:i').' (IP '.$request->ip().'). Not you? Change your password.', '/profile', 'security'));
        }
        $prefs['devices'] = array_values(array_slice(array_unique([$device, ...($known ?? [])]), 0, 10));
        $user->forceFill(['last_login_at' => now(), 'preferences' => $prefs])->saveQuietly();
        $token = $user->createToken(substr($request->userAgent() ?? 'spa', 0, 100))->plainTextToken;

        return ['token' => $token, 'user' => $this->profile($user)];
    }

    private static function describeAgent(string $ua): string
    {
        $browser = collect(['Edg' => 'Edge', 'OPR' => 'Opera', 'Chrome' => 'Chrome', 'Firefox' => 'Firefox', 'Safari' => 'Safari'])->first(fn ($n, $k) => str_contains($ua, $k)) ?? 'a browser';
        $os = collect(['iPhone' => 'iPhone', 'iPad' => 'iPad', 'Android' => 'Android', 'Windows' => 'Windows', 'Mac OS' => 'Mac', 'Linux' => 'Linux'])->first(fn ($n, $k) => str_contains($ua, $k));

        return $browser.($os ? " on {$os}" : '');
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
            'two_factor_enabled' => $user->hasTwoFactor(),
            'sso' => SsoController::forEmail($user->email)?->organization_id === $user->organization_id,
        ]);
    }
}
