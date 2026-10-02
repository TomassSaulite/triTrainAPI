<?php

declare(strict_types=1);

namespace App\Engine\Planning;

use App\Enums\PhaseType;
use App\Enums\Sport;
use DateTimeImmutable;

/**
 * What the week builder needs to know about one week of the plan.
 */
final readonly class WeekContext
{
    /**
     * @param  int  $weekInPhase  0-based position of the week within its phase
     * @param  int|null  $weeksIntoBuild  0-based weeks since the build phase began, null before it
     * @param  float  $availableHours  the most training time the athlete has this week
     * @param  array<value-of<Sport>, float>  $sportShare
     * @param  SecondaryRace|null  $secondaryRace  a B or C race in this week
     * @param  SecondaryRace|null  $recoveringFrom  a race last week that this week recovers from
     * @param  SecondaryRace|null  $leadInFor  a B race a few weeks ahead whose sport gets extra focus
     */
    public function __construct(
        public int $index,
        public DateTimeImmutable $monday,
        public PhaseType $phase,
        public int $weekInPhase,
        public ?int $weeksIntoBuild,
        public WeekLoad $load,
        public float $availableHours,
        public bool $isRaceWeek,
        public array $sportShare,
        public ?SecondaryRace $secondaryRace = null,
        public ?SecondaryRace $recoveringFrom = null,
        public ?SecondaryRace $leadInFor = null,
    ) {}
}
