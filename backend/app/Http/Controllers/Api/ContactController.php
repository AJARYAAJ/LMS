<?php

namespace App\Http\Controllers\Api;

use App\Models\Contact;
use App\Support\Rules;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ContactController extends ResourceController
{
    protected string $model = Contact::class;

    protected array $with = ['account:id,name', 'owner:id,name,avatar_color'];

    protected array $withCount = ['deals'];

    public function index(Request $request): JsonResponse
    {
        return response()->json(
            $this->query($request)
                ->when($request->query('search'), fn (Builder $q, $s) => $q->where(fn ($q) => $q
                    ->whereLike('first_name', "%{$s}%")->orWhereLike('last_name', "%{$s}%")
                    ->orWhereLike('email', "%{$s}%")->orWhereLike('phone', "%{$s}%")))
                ->when($request->query('account_id'), fn ($q, $v) => $q->where('account_id', $v))
                ->latest()
                ->paginate(min((int) $request->query('per_page', 25), 100))
        );
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $contact = $this->query($request)->with(['deals.stage:id,name,color'])->findOrFail($id);

        return response()->json(['data' => $contact]);
    }

    protected function rules(Request $request, ?Model $record = null): array
    {
        return [
            'first_name' => [$record ? 'sometimes' : 'required', 'string', 'max:100'],
            'last_name' => ['nullable', 'string', 'max:100'],
            'email' => ['nullable', 'email', 'max:190'],
            'phone' => ['nullable', 'string', 'max:40'],
            'job_title' => ['nullable', 'string', 'max:120'],
            'account_id' => ['nullable', Rules::exists('accounts')],
            'owner_id' => ['nullable', Rules::exists('users')],
        ];
    }
}
