<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\RacePriority;
use App\Enums\RevisionReason;
use App\Http\Controllers\Controller;
use App\Http\Resources\PlanResource;
use App\Http\Resources\PlanRevisionResource;
use App\Models\Plan;
use App\Models\Race;
use App\Services\Planning\PlanProgress;
use App\Services\Planning\PlanService;
use App\Services\Planning\WeeklyReviewService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class PlanController extends Controller
{
    public function __construct(
        private readonly PlanService $plans,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $plans = $request->user()->athleteOrFail()->plans()->with('race')->latest('id')->get();

        return PlanResource::collection($plans);
    }

    public function current(Request $request): PlanResource|JsonResponse
    {
        $plan = $request->user()->athleteOrFail()->activePlan()->first();

        if ($plan === null) {
            return response()->json(['message' => 'There is no active plan. Create one from an A race.'], 404);
        }

        return $this->show($plan);
    }

    /**
     * Generates a plan for an A race. Any active plan is archived.
     */
    public function store(Race $race): JsonResponse
    {
        Gate::authorize('update', $race);
        abort_unless($race->priority === RacePriority::A, 422, 'Plans are built around an A race; B and C races fit inside one.');
        abort_unless($race->distance->isTriathlon(), 422, 'Plans are built around a triathlon; add this race as a B or C race inside one.');

        $plan = $this->plans->create($race->athlete, $race);

        return $this->show($plan)->response()->setStatusCode(201);
    }

    public function show(Plan $plan): PlanResource
    {
        Gate::authorize('view', $plan);

        $plan->load([
            'race',
            'phases',
            'weeks' => fn ($q) => $q->with('phase')->withSum(['workouts as planned_tss' => fn ($w) => $w->whereNull('parent_id')], 'target_tss'),
        ]);

        return new PlanResource($plan);
    }

    /**
     * Re-plans from today, e.g. after new thresholds, preferences or hours.
     */
    public function regenerate(Request $request, Plan $plan): PlanResource
    {
        Gate::authorize('update', $plan);
        $data = $request->validate(['reason' => ['sometimes', 'string', 'max:255']]);

        $this->plans->regenerate($plan, RevisionReason::Regenerated, $data['reason'] ?? 'Re-planned on request.');

        return $this->show($plan);
    }

    public function archive(Plan $plan): PlanResource
    {
        Gate::authorize('update', $plan);

        return new PlanResource($this->plans->archive($plan)->load('race'));
    }

    /**
     * Planned versus done for every week of the plan.
     */
    public function progress(Plan $plan, PlanProgress $progress): JsonResponse
    {
        Gate::authorize('view', $plan);

        return response()->json(['data' => $progress->weeks($plan)]);
    }

    /**
     * The coach's summary of a week, by default last week. Data is null when
     * the week is not part of the plan, e.g. in a plan's first week.
     */
    public function weeklyReview(Request $request, Plan $plan, WeeklyReviewService $reviews): JsonResponse
    {
        Gate::authorize('view', $plan);
        $validated = $request->validate(['week' => ['sometimes', 'date_format:Y-m-d']]);
        $week = isset($validated['week']) ? CarbonImmutable::parse($validated['week']) : null;

        return response()->json(['data' => $reviews->review($plan, $week)]);
    }

    /**
     * The "what changed and why" log, newest first.
     */
    public function revisions(Plan $plan): AnonymousResourceCollection
    {
        Gate::authorize('view', $plan);

        return PlanRevisionResource::collection($plan->revisions()->paginate(20));
    }
}
