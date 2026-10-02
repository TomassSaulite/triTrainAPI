<?php

declare(strict_types=1);

namespace App\Engine\Planning;

final readonly class GeneratedPlan
{
    /**
     * @param  list<PhaseDraft>  $phases
     * @param  list<WeekDraft>  $weeks
     * @param  list<string>  $warnings  things the athlete should be told, e.g. a lowered target
     */
    public function __construct(
        public float $startingCtl,
        public float $targetCtl,
        public float $projectedRaceDayTsb,
        public array $phases,
        public array $weeks,
        public array $warnings,
    ) {}

    /**
     * @return list<WorkoutDraft>
     */
    public function workouts(): array
    {
        return array_merge(...array_map(fn (WeekDraft $w) => $w->workouts, $this->weeks));
    }
}
