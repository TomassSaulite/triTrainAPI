<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\ActivitySource;
use App\Enums\Sport;
use App\Enums\TssMethod;
use App\Http\Controllers\Controller;
use App\Http\Requests\ActivityRequest;
use App\Http\Requests\ImportActivitiesRequest;
use App\Http\Resources\ActivityResource;
use App\Jobs\ProcessActivity;
use App\Models\Activity;
use App\Services\ActivityImporter;
use App\Services\ActivityPipeline;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class ActivityController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'from' => ['sometimes', 'date'],
            'to' => ['sometimes', 'date', 'after_or_equal:from'],
            'sport' => ['sometimes', Rule::enum(Sport::class)],
        ]);

        $athlete = $request->user()->athleteOrFail();
        // `from` and `to` are the athlete's own calendar days, not UTC ones.
        $day = fn (string $date) => $athlete->utcBounds(CarbonImmutable::parse($date), CarbonImmutable::parse($date));

        $activities = $athlete->activities()
            ->with(['plannedWorkout', 'feedback'])
            ->when($filters['from'] ?? null, fn ($q, $from) => $q->where('started_at', '>=', $day($from)[0]))
            ->when($filters['to'] ?? null, fn ($q, $to) => $q->where('started_at', '<=', $day($to)[1]))
            ->when($filters['sport'] ?? null, fn ($q, $sport) => $q->where('sport', $sport))
            ->orderByDesc('started_at')
            ->paginate(50);

        return ActivityResource::collection($activities);
    }

    public function store(ActivityRequest $request): ActivityResource
    {
        $activity = $request->user()->athleteOrFail()->activities()->create([
            ...$request->validated(),
            'source' => ActivitySource::Manual,
            'tss_method' => $request->filled('tss') ? TssMethod::Provided : null,
        ]);

        ProcessActivity::dispatch($activity);

        return new ActivityResource($activity->refresh()->load(['plannedWorkout', 'feedback']));
    }

    /**
     * Batch import from Health Connect or FIT files, idempotent by external_id.
     */
    public function import(ImportActivitiesRequest $request, ActivityImporter $importer): JsonResponse
    {
        $counts = $importer->import(
            $request->user()->athleteOrFail(),
            $request->enum('source', ActivitySource::class),
            $request->validated('activities'),
        );

        return response()->json(['data' => $counts], $counts['created'] > 0 ? 201 : 200);
    }

    public function show(Activity $activity): ActivityResource
    {
        Gate::authorize('view', $activity);

        return new ActivityResource($activity->load(['plannedWorkout', 'feedback']));
    }

    public function update(ActivityRequest $request, Activity $activity, ActivityPipeline $pipeline): ActivityResource
    {
        Gate::authorize('update', $activity);
        abort_unless($activity->source === ActivitySource::Manual, 403, 'Only manually entered activities can be edited.');

        $previousStart = $activity->started_at;
        $activity->fill($request->validated());

        if ($activity->isDirty('tss')) {
            $activity->tss_method = $activity->tss === null ? null : TssMethod::Provided;
        }

        if ($activity->isDirty(['started_at', 'sport'])) {
            $pipeline->detach($activity);
        }

        $activity->save();

        ProcessActivity::dispatch($activity, rebuildFrom: $previousStart->min($activity->started_at));

        return new ActivityResource($activity->refresh()->load(['plannedWorkout', 'feedback']));
    }

    public function destroy(Activity $activity, ActivityPipeline $pipeline): Response
    {
        Gate::authorize('delete', $activity);

        $pipeline->remove($activity);

        return response()->noContent();
    }
}
