<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Engine\Planning\Template;
use App\Http\Controllers\Controller;
use App\Http\Resources\PlannedWorkoutDetailResource;
use App\Models\PlannedWorkout;
use App\Services\Planning\WorkoutEditor;
use App\Services\Planning\WorkoutPresenter;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class PlannedWorkoutController extends Controller
{
    public function __construct(
        private readonly WorkoutPresenter $presenter,
        private readonly WorkoutEditor $editor,
    ) {}

    public function show(PlannedWorkout $plannedWorkout): PlannedWorkoutDetailResource
    {
        Gate::authorize('view', $plannedWorkout);

        return $this->detail($plannedWorkout);
    }

    /**
     * Steps with absolute targets, ready to encode as a FIT workout.
     */
    public function export(PlannedWorkout $plannedWorkout): JsonResponse
    {
        Gate::authorize('view', $plannedWorkout);

        return response()->json(['data' => $this->presenter->export($plannedWorkout)]);
    }

    /**
     * Moves the session to another day and/or changes its length.
     */
    public function update(Request $request, PlannedWorkout $plannedWorkout): PlannedWorkoutDetailResource
    {
        Gate::authorize('update', $plannedWorkout);
        $data = $request->validate([
            'date' => ['required_without:duration_s', 'date_format:Y-m-d'],
            'duration_s' => ['required_without:date', 'integer', 'between:'.WorkoutEditor::MIN_SECONDS.','.WorkoutEditor::MAX_SECONDS],
        ]);

        if (isset($data['duration_s'])) {
            $this->editor->resize($plannedWorkout, (int) $data['duration_s']);
        }

        if (isset($data['date'])) {
            $this->editor->move($plannedWorkout->refresh(), CarbonImmutable::parse($data['date']));
        }

        return $this->detail($plannedWorkout->refresh());
    }

    /**
     * Workouts from the athlete's library that could replace this session.
     */
    public function alternatives(PlannedWorkout $plannedWorkout): JsonResponse
    {
        Gate::authorize('view', $plannedWorkout);

        return response()->json(['data' => array_map(fn (Template $t) => [
            'id' => $t->id,
            'name' => $t->name,
            'kind' => $t->kind,
            'min_s' => $t->minSeconds,
            'max_s' => $t->maxSeconds,
            'is_personal' => $t->isPersonal,
        ], $this->editor->alternatives($plannedWorkout))]);
    }

    public function swap(Request $request, PlannedWorkout $plannedWorkout): PlannedWorkoutDetailResource
    {
        Gate::authorize('update', $plannedWorkout);
        $data = $request->validate(['template_id' => ['required', 'integer']]);

        return $this->detail($this->editor->swap($plannedWorkout, (int) $data['template_id']));
    }

    public function skip(PlannedWorkout $plannedWorkout): PlannedWorkoutDetailResource
    {
        Gate::authorize('update', $plannedWorkout);

        return $this->detail($this->editor->skip($plannedWorkout));
    }

    private function detail(PlannedWorkout $workout): PlannedWorkoutDetailResource
    {
        $workout->load(['children', 'activity.feedback']);

        return new PlannedWorkoutDetailResource($workout, $this->presenter->resolved($workout));
    }
}
