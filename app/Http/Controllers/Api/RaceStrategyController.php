<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Engine\Race\RaceStrategist;
use App\Http\Controllers\Controller;
use App\Http\Resources\RaceStrategyResource;
use App\Models\Race;
use App\Services\ThresholdService;
use Illuminate\Support\Facades\Gate;

class RaceStrategyController extends Controller
{
    /**
     * The race-day plan for a race: pacing per leg from the athlete's current
     * thresholds, predicted splits and fueling.
     */
    public function __invoke(Race $race, RaceStrategist $strategist, ThresholdService $thresholds): RaceStrategyResource
    {
        Gate::authorize('view', $race);
        $athlete = $race->athlete;

        return new RaceStrategyResource($strategist->plan(
            $race->distance,
            $thresholds->setFor($athlete),
            $athlete->experience,
            $athlete->weight_kg,
        ));
    }
}
