<?php

declare(strict_types=1);

namespace App\Engine\Load;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * The impulse-response fitness model: exponentially weighted averages of daily
 * TSS over 42 days (fitness, CTL) and 7 days (fatigue, ATL).
 *
 *   CTL_d = CTL_{d-1} + (TSS_d - CTL_{d-1}) / 42
 *   ATL_d = ATL_{d-1} + (TSS_d - ATL_{d-1}) / 7
 *   TSB_d = CTL_{d-1} - ATL_{d-1}
 */
final class LoadModel
{
    public const int CTL_DAYS = 42;

    public const int ATL_DAYS = 7;

    /**
     * Rough TSS one hour of mixed triathlon training produces, used to seed
     * fitness for athletes without history.
     */
    public const float TSS_PER_TRAINING_HOUR = 52.5;

    /**
     * Builds one point per day from $from to $to inclusive. Days without an
     * entry in $dailyTss count as rest days.
     *
     * @param  array<string, float>  $dailyTss  keyed by Y-m-d
     * @return list<DailyLoadPoint>
     */
    public function series(array $dailyTss, DateTimeImmutable $from, DateTimeImmutable $to, LoadState $seed = new LoadState): array
    {
        if ($to < $from) {
            throw new InvalidArgumentException('The end date must not be before the start date.');
        }

        $points = [];
        $state = $seed;

        for ($day = $from; $day <= $to; $day = $day->modify('+1 day')) {
            $tss = (float) ($dailyTss[$day->format('Y-m-d')] ?? 0.0);
            $tsb = $state->tsb();
            $state = $this->step($state, $tss);

            $points[] = new DailyLoadPoint($day, $tss, $state->ctl, $state->atl, $tsb);
        }

        return $points;
    }

    public function step(LoadState $previous, float $tss): LoadState
    {
        return new LoadState(
            ctl: $previous->ctl + ($tss - $previous->ctl) / self::CTL_DAYS,
            atl: $previous->atl + ($tss - $previous->atl) / self::ATL_DAYS,
        );
    }

    /**
     * Applies $days days of constant daily TSS in closed form.
     */
    public function project(LoadState $start, float $dailyTss, int $days): LoadState
    {
        $ctlDecay = $this->decay(self::CTL_DAYS, $days);
        $atlDecay = $this->decay(self::ATL_DAYS, $days);

        return new LoadState(
            ctl: $dailyTss + ($start->ctl - $dailyTss) * $ctlDecay,
            atl: $dailyTss + ($start->atl - $dailyTss) * $atlDecay,
        );
    }

    /**
     * The constant daily TSS that moves CTL from $fromCtl to $toCtl in $days days.
     */
    public function dailyTssToReach(float $fromCtl, float $toCtl, int $days): float
    {
        if ($days < 1) {
            throw new InvalidArgumentException('At least one day is needed to change fitness.');
        }

        $decay = $this->decay(self::CTL_DAYS, $days);

        return ($toCtl - $fromCtl * $decay) / (1 - $decay);
    }

    /**
     * Fitness estimate for an athlete without activity history: what their
     * self-reported weekly hours would sustain.
     */
    public function estimateCtlFromWeeklyHours(float $weeklyHours): float
    {
        return round($weeklyHours * self::TSS_PER_TRAINING_HOUR / 7, 1);
    }

    private function decay(int $timeConstant, int $days): float
    {
        return (1 - 1 / $timeConstant) ** $days;
    }
}
