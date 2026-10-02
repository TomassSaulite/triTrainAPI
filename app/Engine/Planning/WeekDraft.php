<?php

declare(strict_types=1);

namespace App\Engine\Planning;

use App\Enums\PhaseType;
use DateTimeImmutable;

final readonly class WeekDraft
{
    /**
     * @param  int  $index  0-based week number counted from the start of the whole plan
     * @param  DateTimeImmutable  $startDate  the Monday of the week
     * @param  list<WorkoutDraft>  $workouts  top-level workouts (brick halves are children)
     */
    public function __construct(
        public int $index,
        public DateTimeImmutable $startDate,
        public PhaseType $phase,
        public bool $isRecovery,
        public float $targetTss,
        public float $targetHours,
        public float $projectedCtl,
        public array $workouts,
    ) {}

    public function plannedTss(): float
    {
        return array_sum(array_map(fn (WorkoutDraft $w) => $w->tss, $this->workouts));
    }

    public function plannedSeconds(): int
    {
        return array_sum(array_map(fn (WorkoutDraft $w) => $w->durationSeconds, $this->workouts));
    }
}
