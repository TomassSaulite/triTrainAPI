<?php

declare(strict_types=1);

namespace App\Engine\Planning;

use App\Enums\PhaseType;
use App\Enums\RaceDistance;

/**
 * Splits the weeks before a race into phases, working backwards from race day:
 * taper (1 week for half, 2 for full), then 2-3 weeks of race-specific peak,
 * then build (about 40% of what is left), and base for the rest.
 */
final class PhasePlanner
{
    public const float BUILD_SHARE = 0.4;

    /**
     * Plans long enough to afford a third peak week get one.
     */
    public const int LONG_PEAK_THRESHOLD_WEEKS = 12;

    /**
     * Races early in the week leave few taper days in race week, so the taper
     * starts a week sooner for races early in the week (Monday to Wednesday).
     */
    public const int SHORT_RACE_WEEK_DAYS = 3;

    /**
     * @param  int  $daysBeforeRace  days of race week before race day (0 for a Monday race)
     * @return list<PhaseType> one phase per week, in calendar order
     */
    public function sequence(int $totalWeeks, RaceDistance $distance, int $daysBeforeRace = 6): array
    {
        $taperWeeks = $distance->taperWeeks() + ($daysBeforeRace < self::SHORT_RACE_WEEK_DAYS ? 1 : 0);
        $taper = min($totalWeeks, $taperWeeks);
        $remaining = $totalWeeks - $taper;

        $peak = min($remaining, $remaining >= self::LONG_PEAK_THRESHOLD_WEEKS ? 3 : 2);
        $remaining -= $peak;

        $build = (int) round($remaining * self::BUILD_SHARE);
        $base = $remaining - $build;

        return [
            ...array_fill(0, $base, PhaseType::Base),
            ...array_fill(0, $build, PhaseType::Build),
            ...array_fill(0, $peak, PhaseType::Peak),
            ...array_fill(0, $taper, PhaseType::Taper),
        ];
    }
}
