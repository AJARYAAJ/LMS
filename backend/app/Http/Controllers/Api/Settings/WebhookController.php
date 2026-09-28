<?php

namespace App\Http\Controllers\Api\Settings;

use App\Http\Controllers\Api\ResourceController;
use App\Jobs\DeliverWebhook;
use App\Models\Webhook;
use App\Services\WebhookDispatcher;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class WebhookController extends ResourceController
{
    protected string $model = Webhook::class;

    protected function rules(Request $request, ?Model $record = null): array
    {
        $req = $record ? 'sometimes' : 'required';

        return [
            'name' => [$req, 'string', 'max:120'],
            'url' => [$req, 'url:https,http', 'max:500'],
            'events' => [$req, 'array', 'min:1'],
            'events.*' => [Rule::in([...WebhookDispatcher::EVENTS, '*'])],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate($this->rules($request));
        $secret = 'whsec_'.Str::random(32);
        $webhook = Webhook::create([...$data, 'secret' => $secret]);
        $this->audit->log('webhook.created', $webhook, [], $data);

        // The signing secret is only revealed once.
        return response()->json(['data' => $webhook, 'secret' => $secret], 201);
    }

    public function test(int $id): JsonResponse
    {
        $webhook = Webhook::findOrFail($id);
        DeliverWebhook::dispatch($webhook->id, 'ping', ['message' => 'Test delivery from LMS']);

        return response()->json(['message' => 'Test event queued.']);
    }
}
