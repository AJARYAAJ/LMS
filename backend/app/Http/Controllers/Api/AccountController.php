<?php

namespace App\Http\Controllers\Api;

use App\Models\Account;
use App\Support\Rules;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AccountController extends ResourceController
{
    protected string $model = Account::class;

    protected array $with = ['owner:id,name,avatar_color'];

    protected array $withCount = ['contacts', 'deals'];

    protected function query(Request $request): Builder
    {
        return parent::query($request)->withSum(['deals as open_pipeline' => fn ($q) => $q->where('status', 'open')], 'amount');
    }

    public function index(Request $request): JsonResponse
    {
        return response()->json(
            $this->query($request)
                ->when($request->query('search'), fn (Builder $q, $s) => $q->where(fn ($q) => $q
                    ->whereLike('name', "%{$s}%")->orWhereLike('domain', "%{$s}%")->orWhereLike('industry', "%{$s}%")))
                ->orderBy('name')
                ->paginate(min((int) $request->query('per_page', 25), 100))
        );
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $account = $this->query($request)
            ->with(['contacts:id,account_id,first_name,last_name,email,phone,job_title', 'deals.stage:id,name,color'])
            ->findOrFail($id);

        return response()->json(['data' => $account]);
    }

    protected function rules(Request $request, ?Model $record = null): array
    {
        return [
            'name' => [$record ? 'sometimes' : 'required', 'string', 'max:190'],
            'domain' => ['nullable', 'string', 'max:190'],
            'industry' => ['nullable', 'string', 'max:120'],
            'company_size' => ['nullable', 'string', 'max:40'],
            'phone' => ['nullable', 'string', 'max:40'],
            'website' => ['nullable', 'string', 'max:190'],
            'city' => ['nullable', 'string', 'max:120'],
            'country' => ['nullable', 'string', 'max:120'],
            'annual_revenue' => ['nullable', 'numeric', 'min:0'],
            'owner_id' => ['nullable', Rules::exists('users')],
            'description' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
