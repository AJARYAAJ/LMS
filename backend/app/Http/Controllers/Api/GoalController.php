<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Goal;
use App\Models\User;
use App\Reports\GoalTracker;
use App\Support\Rules;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class GoalController extends Controller
{
    public function __construct(private GoalTracker $tracker) {}

    /** Managers see every goal; reps see their own and the team's. `?mine=1` narrows to both of those for anyone. */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $goals = Goal::with('user:id,name,avatar_color')
            ->when($request->boolean('mine') || $user->role === User::SALES_REP, fn ($q) => $q->where(fn ($w) => $w->where('user_id', $user->id)->orWhereNull('user_id')))
            ->orderByRaw('case when user_id is null then 0 else 1 end')->orderBy('metric')
            ->get();

        return response()->json(['data' => $goals->map(fn (Goal $g) => $this->tracker->progress($g))->values()]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizeManage($request);
        $goal = Goal::create([...$this->validated($request), 'created_by' => $request->user()->id]);

        return response()->json(['data' => $this->tracker->progress($goal->load('user'))], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $this->authorizeManage($request);
        $goal = Goal::findOrFail($id);
        $goal->update($this->validated($request, true));

        return response()->json(['data' => $this->tracker->progress($goal->fresh('user'))]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $this->authorizeManage($request);
        Goal::findOrFail($id)->delete();

        return response()->json(null, 204);
    }

    private function authorizeManage(Request $request): void
    {
        abort_unless($request->user()->hasRole(User::ADMIN, User::MANAGER), 403, 'Only admins and managers can set goals.');
    }

    private function validated(Request $request, bool $partial = false): array
    {
        $req = $partial ? 'sometimes' : 'required';

        return $request->validate([
            'user_id' => ['nullable', Rules::exists('users')],
            'metric' => [$req, Rule::in(array_keys(GoalTracker::METRICS))],
            'period' => ['sometimes', Rule::in(Goal::PERIODS)],
            'target' => [$req, 'numeric', 'min:0.01', 'max:999999999'],
        ]);
    }
}
