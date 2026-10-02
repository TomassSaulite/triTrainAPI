<?php

declare(strict_types=1);

namespace App\Engine\Planning;

use App\Enums\RaceDistance;
use App\Enums\RacePriority;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * A B or C race inside an A-race plan. A B race gets a mini-taper; a C race is
 * raced as training.
 */
final readonly class SecondaryRace
{
    /**
     * Share of a normal week's load kept in the week of the race.
     */
    public const array WEEK_LOAD = ['B' => 0.75, 'C' => 0.9];

    public function __construct(
        public DateTimeImmutable $date,
        public RacePriority $priority,
        public RaceDistance $distance,
        public string $name = '',
    ) {
        if ($priority === RacePriority::A) {
            throw new InvalidArgumentException('Only B and C races sit inside a plan.');
        }
    }

    public function weekLoadFactor(): float
    {
        return self::WEEK_LOAD[$this->priority->value];
    }

    /**
     * B races are prepared for: a rest day before and the long sessions of the week are replaced.
     */
    public function isMiniTaper(): bool
    {
        return $this->priority === RacePriority::B;
    }

    public function isInWeek(DateTimeImmutable $monday): bool
    {
        return $this->date >= $monday && $this->date < $monday->modify('+7 days');
    }

    /**
     * Whether the week starting $monday is one of the lead-in weeks before
     * this race (B races only: C races are trained through).
     */
    public function hasLeadInWeek(DateTimeImmutable $monday): bool
    {
        if (! $this->isMiniTaper() || $this->distance->leadInWeeks() === 0) {
            return false;
        }

        $weeksBefore = intdiv((int) $monday->diff($this->raceMonday())->format('%r%a'), 7);

        return $weeksBefore >= 1 && $weeksBefore <= $this->distance->leadInWeeks();
    }

    /**
     * Whether the week starting $monday is the recovery week right after the race.
     */
    public function hasRecoveryWeek(DateTimeImmutable $monday): bool
    {
        return $this->distance->recoveryWeekLoad() !== null
            && $this->raceMonday()->modify('+7 days')->format('Y-m-d') === $monday->format('Y-m-d');
    }

    private function raceMonday(): DateTimeImmutable
    {
        return $this->date->setTime(0, 0)->modify('-'.((int) $this->date->format('N') - 1).' days');
    }
}
