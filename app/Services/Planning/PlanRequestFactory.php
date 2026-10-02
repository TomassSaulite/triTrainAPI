<?php

declare(strict_types=1);

namespace App\Services\Planning;

use App\Engine\Planning\PlanRequest;
use App\Engine\Planning\SecondaryRace;
use App\Enums\RacePriority;
use App\Models\Athlete;
use App\Models\AvailabilityOverride;
use App\Models\Race;
use App\Services\LoadService;
use App\Services\ThresholdService;
use Carbon\CarbonImmutable;

/**
 * Gathers everything the engine needs about an athlete into a PlanRequest.
 */
class PlanRequestFactory
{
    public function __construct(
        private readonly LoadService $load,
        private readonly ThresholdService $thresholds,
    ) {}

    /**
     * @param  CarbonImmutable|null  $planStartDate  when re-planning, the day the plan originally began
     */
    public function make(Athlete $athlete, Race $race, CarbonImmutable $startDate, ?CarbonImmutable $planStartDate = null): PlanRequest
    {
        $overrides = $athlete->availabilityOverrides()
            ->whereDate('date', '>=', $startDate)
            ->whereDate('date', '<=', $race->date)
            ->get();
        $availability = $overrides
            ->mapWithKeys(fn (AvailabilityOverride $o) => [$o->date->toDateString() => $o->available_minutes])
            ->all();

        return new PlanRequest(
            distance: $race->distance,
            raceDate: $race->date->toImmutable()->startOfDay(),
            startDate: $startDate->startOfDay(),
            experience: $athlete->experience,
            weeklyHours: $athlete->weekly_hours,
            currentLoad: $this->load->stateOn($athlete, $startDate->subDay()),
            preferences: $athlete->prefs,
            thresholds: $this->thresholds->setFor($athlete),
            age: $athlete->ageOn($startDate),
            weakestSport: $athlete->weakest_sport,
            availability: $availability,
            secondaryRaces: $athlete->races()
                ->whereKeyNot($race->id)
                ->whereIn('priority', [RacePriority::B, RacePriority::C])
                ->whereDate('date', '>=', $startDate)
                ->whereDate('date', '<', $race->date)
                ->get()
                ->map(fn (Race $r) => new SecondaryRace($r->date->toImmutable()->startOfDay(), $r->priority, $r->distance, $r->name))
                ->all(),
            planStartDate: $planStartDate?->startOfDay(),
            easyDays: $overrides->where('easy_only', true)->map(fn (AvailabilityOverride $o) => $o->date->toDateString())->values()->all(),
        );
    }
}
