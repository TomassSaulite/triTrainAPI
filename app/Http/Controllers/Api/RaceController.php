<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\RaceDistance;
use App\Enums\RacePriority;
use App\Http\Controllers\Controller;
use App\Http\Requests\RaceRequest;
use App\Http\Resources\RaceResource;
use App\Models\Race;
use App\Services\Planning\Replanner;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

class RaceController extends Controller
{
    public function __construct(
        private readonly Replanner $replanner,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $athlete = $request->user()->athleteOrFail();
        $races = $athlete->races()
            ->when($request->boolean('upcoming'), fn ($q) => $q->whereDate('date', '>=', $athlete->today()))
            ->orderBy('date')
            ->get();

        return RaceResource::collection($races);
    }

    public function store(RaceRequest $request): RaceResource
    {
        $athlete = $request->user()->athleteOrFail();
        $race = $athlete->races()->create([
            'priority' => $request->defaultPriority($request->enum('distance', RaceDistance::class)),
            ...$request->validated(),
        ]);

        if ($race->priority !== RacePriority::A) {
            $this->replanner->inputsChanged($athlete, "Re-planned around the new {$race->priority->value} race \"{$race->name}\".", $race->date);
        }

        return new RaceResource($race);
    }

    public function show(Race $race): RaceResource
    {
        Gate::authorize('view', $race);

        return new RaceResource($race);
    }

    public function update(RaceRequest $request, Race $race): RaceResource
    {
        Gate::authorize('update', $race);

        $previousDate = $race->date->copy();
        $race->update($request->validated());

        if ($race->wasChanged(['date', 'distance', 'priority'])) {
            $this->replanner->inputsChanged(
                $race->athlete,
                "Re-planned because \"{$race->name}\" changed.",
                $this->drivesActivePlan($race) ? null : $previousDate->min($race->date),
            );
        }

        return new RaceResource($race);
    }

    public function destroy(Race $race): Response
    {
        Gate::authorize('delete', $race);

        $athlete = $race->athlete;
        $drivesPlan = $this->drivesActivePlan($race);
        $race->delete();

        // Deleting the A race archives its plan (see Race::booted); anything else just re-plans.
        if (! $drivesPlan) {
            $this->replanner->inputsChanged($athlete, "Re-planned without \"{$race->name}\".", $race->date);
        }

        return response()->noContent();
    }

    private function drivesActivePlan(Race $race): bool
    {
        return $race->athlete->activePlan()->where('race_id', $race->id)->exists();
    }
}
