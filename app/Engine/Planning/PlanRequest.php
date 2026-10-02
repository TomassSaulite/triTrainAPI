<?php

declare(strict_types=1);

namespace App\Engine\Planning;

use App\Engine\Load\LoadState;
use App\Engine\ThresholdSet;
use App\Enums\Experience;
use App\Enums\RaceDistance;
use App\Enums\Sport;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Everything the generator needs to know about the athlete and their goal.
 */
final readonly class PlanRequest
{
    /**
     * @param  DateTimeImmutable  $startDate  first day sessions may be scheduled
     * @param  LoadState  $currentLoad  fitness and fatigue on the day before $startDate
     * @param  array<string, int>  $availability  per-date overrides (Y-m-d => available minutes)
     * @param  list<SecondaryRace>  $secondaryRaces  B and C races before the A race
     * @param  list<string>  $easyDays  Y-m-d dates that may only hold easy sessions, such as the first days back after illness
     * @param  DateTimeImmutable|null  $planStartDate  when re-planning, the day the original plan began, so
     *                                                 phases stay anchored to the whole plan rather than restarting
     */
    public function __construct(
        public RaceDistance $distance,
        public DateTimeImmutable $raceDate,
        public DateTimeImmutable $startDate,
        public Experience $experience,
        public float $weeklyHours,
        public LoadState $currentLoad,
        public CoachPreferences $preferences = new CoachPreferences,
        public ThresholdSet $thresholds = new ThresholdSet,
        public ?int $age = null,
        public ?Sport $weakestSport = null,
        public array $availability = [],
        public array $secondaryRaces = [],
        public ?DateTimeImmutable $planStartDate = null,
        public array $easyDays = [],
    ) {
        if (! $distance->isTriathlon()) {
            throw new InvalidArgumentException('Plans are built around a triathlon; other races fit inside one as B or C races.');
        }

        if ($raceDate <= $startDate) {
            throw new InvalidArgumentException('The race must be after the plan start date.');
        }

        if ($planStartDate !== null && $planStartDate > $startDate) {
            throw new InvalidArgumentException('The original plan cannot start after the re-plan date.');
        }

        if ($weeklyHours <= 0) {
            throw new InvalidArgumentException('Weekly hours must be positive.');
        }
    }

    /**
     * The day the plan as a whole began: phases are laid out from here.
     */
    public function phaseAnchor(): DateTimeImmutable
    {
        return $this->planStartDate ?? $this->startDate;
    }

    /**
     * The B or C race in the week starting $monday, if any (the more important one wins).
     */
    public function secondaryRaceInWeek(DateTimeImmutable $monday): ?SecondaryRace
    {
        $inWeek = array_filter($this->secondaryRaces, fn (SecondaryRace $r) => $r->isInWeek($monday) && $r->date < $this->raceDate);
        usort($inWeek, fn (SecondaryRace $a, SecondaryRace $b) => [$a->priority->value, $a->date] <=> [$b->priority->value, $b->date]);

        return $inWeek[0] ?? null;
    }

    /**
     * The B race whose lead-in includes the week starting $monday, if any.
     */
    public function leadInRace(DateTimeImmutable $monday): ?SecondaryRace
    {
        foreach ($this->secondaryRaces as $race) {
            if ($race->date < $this->raceDate && $race->hasLeadInWeek($monday)) {
                return $race;
            }
        }

        return null;
    }

    /**
     * The race the athlete is recovering from in the week starting $monday, if any.
     */
    public function recoveringFrom(DateTimeImmutable $monday): ?SecondaryRace
    {
        foreach ($this->secondaryRaces as $race) {
            if ($race->date < $this->raceDate && $race->hasRecoveryWeek($monday)) {
                return $race;
            }
        }

        return null;
    }

    public function availableMinutesOn(DateTimeImmutable $date): ?int
    {
        return $this->availability[$date->format('Y-m-d')] ?? null;
    }

    public function isEasyDay(DateTimeImmutable $date): bool
    {
        return in_array($date->format('Y-m-d'), $this->easyDays, true);
    }
}
