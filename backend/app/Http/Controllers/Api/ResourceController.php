<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AuditLogger;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Minimal tenant-scoped CRUD used by the configuration resources
 * (statuses, sources, tags, rules, ...). Subclasses declare the model,
 * validation rules and default ordering.
 */
abstract class ResourceController extends Controller
{
    /** @var class-string<Model> */
    protected string $model;

    protected array $with = [];

    protected array $withCount = [];

    protected string $orderBy = 'id';

    protected string $auditName;

    abstract protected function rules(Request $request, ?Model $record = null): array;

    public function __construct(protected AuditLogger $audit) {}

    protected function query(Request $request): Builder
    {
        return $this->model::query()->with($this->with)->withCount($this->withCount);
    }

    public function index(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->query($request)->orderBy($this->orderBy)->get()]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        return response()->json(['data' => $this->query($request)->findOrFail($id)]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->prepare($request->validate($this->rules($request)), $request);
        $record = $this->model::create($data);
        $this->afterSave($record, $request);
        $this->audit->log($this->auditName().'.created', $record, [], $record->attributesToArray());

        return response()->json(['data' => $this->query($request)->find($record->getKey())], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $record = $this->query($request)->findOrFail($id);
        $original = $record->getOriginal();
        $record->update($this->prepare($request->validate($this->rules($request, $record)), $request, $record));
        $this->afterSave($record, $request);
        $this->audit->logChanges($this->auditName().'.updated', $record, $original);

        return response()->json(['data' => $this->query($request)->find($record->getKey())]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $record = $this->query($request)->findOrFail($id);
        $this->beforeDelete($record);
        $record->delete();
        $this->audit->log($this->auditName().'.deleted', $record, $record->attributesToArray());

        return response()->json(null, 204);
    }

    protected function prepare(array $data, Request $request, ?Model $record = null): array
    {
        return $data;
    }

    protected function afterSave(Model $record, Request $request): void {}

    protected function beforeDelete(Model $record): void {}

    protected function auditName(): string
    {
        return $this->auditName ?? Str::snake(class_basename($this->model));
    }
}
