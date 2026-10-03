<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Notifications\AppNotification;
use App\Security\Totp;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Turn two-step login (authenticator app) on and off, and manage recovery codes. */
class TwoFactorController extends Controller
{
    public function __construct(private AuditLogger $audit) {}

    public function show(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json(['data' => [
            'enabled' => $user->hasTwoFactor(),
            'confirmed_at' => $user->two_factor_confirmed_at,
            'recovery_codes_left' => count($user->two_factor_recovery_codes ?? []),
        ]]);
    }

    public function setup(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_if($user->hasTwoFactor(), 422, 'Two-step login is already on.');
        $secret = Totp::secret();
        $user->forceFill(['two_factor_secret' => $secret, 'two_factor_confirmed_at' => null])->save();

        return response()->json(['data' => ['secret' => $secret, 'uri' => Totp::uri($secret, $user->email, 'LeadFlow')]]);
    }

    public function confirm(Request $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validate(['code' => ['required', 'string']]);
        abort_unless($user->two_factor_secret, 422, 'Start the setup first.');
        if (! Totp::verify($user->two_factor_secret, $data['code'])) {
            throw ValidationException::withMessages(['code' => 'That code didn’t match. Make sure your phone’s clock is right and try the newest code.']);
        }
        $codes = $this->codes();
        $user->forceFill(['two_factor_confirmed_at' => now(), 'two_factor_recovery_codes' => $codes])->save();
        $this->audit->log('user.two_factor_enabled', $user);
        $user->notify(new AppNotification('Two-step login is on', 'Your account now asks for a code from your authenticator app when you sign in.', '/profile', 'security'));

        return response()->json(['data' => ['recovery_codes' => $codes]]);
    }

    public function regenerate(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->checkPassword($request);
        abort_unless($user->hasTwoFactor(), 422, 'Two-step login is off.');
        $codes = $this->codes();
        $user->forceFill(['two_factor_recovery_codes' => $codes])->save();

        return response()->json(['data' => ['recovery_codes' => $codes]]);
    }

    public function destroy(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->checkPassword($request);
        $user->forceFill(['two_factor_secret' => null, 'two_factor_recovery_codes' => null, 'two_factor_confirmed_at' => null])->save();
        $this->audit->log('user.two_factor_disabled', $user);
        $user->notify(new AppNotification('Two-step login was turned off', 'Your account no longer asks for a code at sign-in. If this wasn’t you, change your password now.', '/profile', 'security'));

        return response()->json(['message' => 'Two-step login turned off.']);
    }

    private function checkPassword(Request $request): void
    {
        $data = $request->validate(['password' => ['required', 'string']]);
        if (! Hash::check($data['password'], $request->user()->password)) {
            throw ValidationException::withMessages(['password' => 'Your password is incorrect.']);
        }
    }

    /** @return list<string> */
    private function codes(): array
    {
        return collect(range(1, 8))->map(fn () => Str::lower(Str::random(5).'-'.Str::random(5)))->all();
    }
}
