<?php

namespace App\Http\Controllers\Api\Settings;

use App\Http\Controllers\Controller;
use App\Models\OAuthApp;
use App\Models\OAuthRefreshToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;

/** Register the OAuth apps that may call the API for people in this workspace. */
class OAuthAppController extends Controller
{
    public function index(): JsonResponse
    {
        $apps = OAuthApp::latest('id')->get();
        $users = OAuthRefreshToken::whereIn('oauth_app_id', $apps->pluck('id'))->whereNull('revoked_at')->where('expires_at', '>', now())
            ->selectRaw('oauth_app_id, count(distinct user_id) as users')->groupBy('oauth_app_id')->pluck('users', 'oauth_app_id');

        return response()->json(['data' => $apps->map(fn (OAuthApp $a) => [...$a->toArray(), 'confidential' => $a->isConfidential(), 'active_users' => (int) ($users[$a->id] ?? 0)])]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $secret = $data['confidential'] ? Str::random(48) : null;
        $app = OAuthApp::create([
            'name' => $data['name'], 'redirect_uris' => $data['redirect_uris'], 'created_by' => $request->user()->id,
            'client_id' => Str::lower(Str::random(32)), 'secret_hash' => $secret ? Hash::make($secret) : null,
        ]);

        return response()->json(['data' => [...$app->toArray(), 'confidential' => (bool) $secret, 'active_users' => 0], 'client_secret' => $secret], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $app = OAuthApp::findOrFail($id);
        $data = $request->validate(['name' => ['sometimes', 'string', 'max:80'], 'redirect_uris' => ['sometimes', 'array', 'min:1', 'max:10'], 'redirect_uris.*' => ['string', 'max:500', $this->uriRule()]]);
        $app->update($data);

        return response()->json(['data' => $app]);
    }

    /** New client secret; the old one stops working immediately. */
    public function rotate(int $id): JsonResponse
    {
        $app = OAuthApp::findOrFail($id);
        abort_unless($app->isConfidential(), 422, 'Public apps have no secret.');
        $secret = Str::random(48);
        $app->forceFill(['secret_hash' => Hash::make($secret)])->save();

        return response()->json(['client_secret' => $secret]);
    }

    /** Deleting an app signs it out everywhere. */
    public function destroy(int $id): JsonResponse
    {
        $app = OAuthApp::findOrFail($id);
        PersonalAccessToken::where('name', $app->tokenName())->delete();
        $app->delete();

        return response()->json(null, 204);
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'redirect_uris' => ['required', 'array', 'min:1', 'max:10'],
            'redirect_uris.*' => ['required', 'string', 'max:500', $this->uriRule()],
            'confidential' => ['required', 'boolean'],
        ]);
    }

    /** https anywhere; http only for local development. */
    private function uriRule(): \Closure
    {
        return function ($attribute, $value, $fail) {
            $p = parse_url((string) $value);
            $local = in_array($p['host'] ?? '', ['localhost', '127.0.0.1'], true);
            if (! isset($p['scheme'], $p['host']) || isset($p['fragment']) || ! ($p['scheme'] === 'https' || ($p['scheme'] === 'http' && $local))) {
                $fail('Redirect URLs must be https:// (http:// only for localhost) and have no #fragment.');
            }
        };
    }
}
