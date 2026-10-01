<?php

namespace App\Http\Controllers\Api\Settings;

use App\Http\Controllers\Api\ResourceController;
use App\Models\AiAgent;
use App\Support\Rules;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class AiAgentController extends ResourceController
{
    protected string $model = AiAgent::class;

    protected array $with = ['integration:id,provider,status'];

    protected string $orderBy = 'name';

    protected function query(Request $request): Builder
    {
        return parent::query($request)->withCount([
            'calls',
            'calls as completed_calls_count' => fn ($q) => $q->where('status', 'completed'),
            'calls as meetings_count' => fn ($q) => $q->where('outcome', 'meeting_booked'),
        ]);
    }

    protected function rules(Request $request, ?Model $record = null): array
    {
        $req = $record ? 'sometimes' : 'required';

        return [
            'name' => [$req, 'string', 'max:80'],
            'mode' => ['sometimes', 'in:outbound,inbound'],
            'transfer_mode' => ['sometimes', 'in:none,owner,number'],
            'transfer_number' => ['nullable', 'required_if:transfer_mode,number', 'string', 'max:40', 'regex:/^\+?[0-9 ()\-]{6,}$/'],
            'integration_id' => ['nullable', Rules::exists('integrations')],
            'goal' => [$req, 'string', 'max:2000'],
            'first_message' => [$req, 'string', 'max:500'],
            'voice' => ['sometimes', 'string', 'max:60'],
            'language' => ['sometimes', 'string', 'max:12'],
            'questions' => ['nullable', 'array', 'max:10'],
            'questions.*.key' => ['nullable', 'string', 'max:40'],
            'questions.*.question' => ['required', 'string', 'max:300'],
            'max_duration_seconds' => ['sometimes', 'integer', 'between:30,1800'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    protected function prepare(array $data, Request $request, ?Model $record = null): array
    {
        if (isset($data['questions'])) {
            $data['questions'] = collect($data['questions'])->map(fn ($q, $i) => [
                'key' => $q['key'] ?: Str::snake(Str::limit($q['question'], 30, '')) ?: "q{$i}",
                'question' => $q['question'],
            ])->values()->all();
        }

        return $data;
    }
}
