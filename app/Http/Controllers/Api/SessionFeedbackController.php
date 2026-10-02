<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\FeelDimension;
use App\Http\Controllers\Controller;
use App\Http\Requests\SessionFeedbackRequest;
use App\Http\Resources\SessionFeedbackResource;
use App\Models\Activity;
use App\Models\SessionFeedback;
use App\Services\Adaptation\AdaptationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

class SessionFeedbackController extends Controller
{
    private const int DEFAULT_DAYS = 56;

    /** The history list covers at most this many days. */
    private const int MAX_DAYS = 365;

    /**
     * How sessions felt over the last `days` days (default 56), oldest first,
     * with the session each rating belongs to.
     */
    public function index(Request $request): JsonResponse
    {
        $days = (int) ($request->validate(['days' => ['sometimes', 'integer', 'between:1,'.self::MAX_DAYS]])['days'] ?? self::DEFAULT_DAYS);
        $athlete = $request->user()->athlete;
        [$from, $to] = $athlete->utcBounds($athlete->today()->subDays($days - 1), $athlete->today());

        $feedback = $athlete->sessionFeedback()
            ->whereHas('activity', fn ($q) => $q->whereBetween('started_at', [$from, $to]))
            ->with('activity.plannedWorkout')
            ->get()
            ->sortBy(fn (SessionFeedback $f) => $f->activity->started_at)
            ->values()
            ->map(fn (SessionFeedback $f) => [
                ...(new SessionFeedbackResource($f))->resolve($request),
                'date' => $athlete->localDate($f->activity->started_at)->toDateString(),
                'sport' => $f->activity->sport,
                'activity_name' => $f->activity->name,
                'duration_s' => $f->activity->duration_s,
                'planned_kind' => $f->activity->plannedWorkout?->kind,
            ]);

        return response()->json(['data' => $feedback]);
    }

    /**
     * Rates a session, or changes its rating. The coach then looks at the plan
     * again; `plan_change` says what it changed, if anything.
     */
    public function upsert(SessionFeedbackRequest $request, Activity $activity, AdaptationService $adaptation): JsonResponse
    {
        Gate::authorize('view', $activity);

        $feedback = $activity->feedback()->firstOrNew();
        // A PUT replaces the whole rating: anything left out is cleared.
        $cleared = [...array_fill_keys(array_column(FeelDimension::cases(), 'value'), null), 'pain' => false, 'pain_area' => null, 'note' => null];
        $feedback->fill([...$cleared, ...$request->validated()]);
        $feedback->athlete()->associate($activity->athlete_id);
        $created = ! $feedback->exists;
        $feedback->save();

        $plan = $activity->athlete->activePlan()->first();
        $revision = $plan === null ? null : $adaptation->adapt($plan);

        return response()->json([
            'data' => new SessionFeedbackResource($feedback),
            'plan_change' => $revision?->summary,
        ], $created ? 201 : 200);
    }

    public function destroy(Activity $activity): Response
    {
        Gate::authorize('view', $activity);
        $activity->feedback()->delete();

        return response()->noContent();
    }
}
