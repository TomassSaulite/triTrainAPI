<?php

declare(strict_types=1);

namespace App\Enums;

use DomainException;

/**
 * Race distances. Triathlons can be the A race a plan is built around; any
 * distance, including single-sport races such as a spring marathon, can sit
 * inside a plan as a B or C race.
 */
enum RaceDistance: string
{
    case Sprint = 'sprint';
    case Olympic = 'olympic';
    case Half = 'half';
    case Full = 'full';
    case FiveK = '5k';
    case TenK = '10k';
    case HalfMarathon = 'half_marathon';
    case Marathon = 'marathon';

    /**
     * @return list<self>
     */
    public static function triathlons(): array
    {
        return [self::Sprint, self::Olympic, self::Half, self::Full];
    }

    public function isTriathlon(): bool
    {
        return in_array($this, self::triathlons(), true);
    }

    /**
     * The single sport of a non-triathlon race.
     */
    public function sport(): ?Sport
    {
        return $this->isTriathlon() ? null : Sport::Run;
    }

    /**
     * Number of taper weeks before race day (race week included).
     */
    public function taperWeeks(): int
    {
        return match ($this) {
            self::Full => 2,
            self::Sprint, self::Olympic, self::Half => 1,
            default => throw $this->notAPlanRace(),
        };
    }

    /**
     * Age-grouper peak CTL range [novice, advanced] for this distance.
     *
     * @return array{0: float, 1: float}
     */
    public function peakCtlRange(): array
    {
        return match ($this) {
            self::Sprint => [40.0, 60.0],
            self::Olympic => [50.0, 70.0],
            self::Half => [65.0, 85.0],
            self::Full => [85.0, 110.0],
            default => throw $this->notAPlanRace(),
        };
    }

    /**
     * Default share of weekly training time per discipline.
     *
     * @return array<value-of<Sport>, float>
     */
    public function defaultSportShare(): array
    {
        return match ($this) {
            self::Sprint, self::Olympic => ['swim' => 0.20, 'bike' => 0.45, 'run' => 0.35],
            self::Half => ['swim' => 0.175, 'bike' => 0.49, 'run' => 0.335],
            self::Full => ['swim' => 0.15, 'bike' => 0.52, 'run' => 0.33],
            default => throw $this->notAPlanRace(),
        };
    }

    /**
     * Weeks of extra focus on the race's sport before a B race of this
     * distance, e.g. building run volume for a marathon.
     */
    public function leadInWeeks(): int
    {
        return match ($this) {
            self::Marathon => 6,
            self::HalfMarathon => 3,
            self::TenK => 2,
            self::FiveK => 1,
            default => 0,
        };
    }

    /**
     * Share of training time moved to the race's sport during the lead-in.
     */
    public function leadInShift(): float
    {
        return match ($this) {
            self::Marathon => 0.12,
            self::HalfMarathon => 0.08,
            self::TenK, self::FiveK => 0.05,
            default => 0.0,
        };
    }

    /**
     * Load kept in the week after the race, when the race's sport stays easy.
     * Null when the race needs no recovery week.
     */
    public function recoveryWeekLoad(): ?float
    {
        return match ($this) {
            self::Marathon => 0.65,
            self::HalfMarathon, self::Full => 0.85,
            default => null,
        };
    }

    private function notAPlanRace(): DomainException
    {
        return new DomainException("A {$this->value} race cannot be the goal of a triathlon plan.");
    }
}
