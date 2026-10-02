<?php

declare(strict_types=1);

namespace App\Engine\Planning;

use App\Engine\Load\LoadModel;
use App\Engine\Load\LoadState;
use App\Enums\Experience;
use App\Enums\PhaseType;

/**
 * Turns a fitness goal into weekly load. Loading weeks ramp CTL by at most the
 * athlete's ramp cap towards the target, every Nth week is a recovery week,
 * the taper sheds load so form peaks on race day, and every week is capped by
 * the hours the athlete has.
 */
final class LoadPlanner
{
    /**
     * Recovery weeks keep this share of the last loading week.
     */
    public const float RECOVERY_WEEK_FACTOR = 0.65;

    /**
     * Load per taper week relative to the last loading week, by taper length.
     * The last entry is race week, whose load excludes the race itself.
     */
    public const array TAPER_FACTORS = [1 => [0.5], 2 => [0.7, 0.4], 3 => [0.8, 0.65, 0.4]];

    /**
     * Athletes at or above this age default to 2:1 loading instead of 3:1.
     */
    public const int OLDER_ATHLETE_AGE = 50;

    /**
     * Days a normal week trains on, for sizing a shortened race week.
     */
    public const int TRAINING_DAYS_PER_WEEK = 6;

    /**
     * Race-day form an A race should land in.
     */
    public const array RACE_DAY_TSB = [5.0, 20.0];

    public function __construct(
        private readonly LoadModel $model = new LoadModel,
    ) {}

    public function targetCtl(PlanRequest $request): float
    {
        if ($request->preferences->targetCtl !== null) {
            return $request->preferences->targetCtl;
        }

        [$low, $high] = $request->distance->peakCtlRange();
        $position = match ($request->experience) {
            Experience::Novice => 0.0,
            Experience::Intermediate => 0.5,
            Experience::Advanced => 1.0,
        };

        return round($low + ($high - $low) * $position, 1);
    }

    /**
     * How often a recovery week comes: 4 means three loading weeks then one easy (3:1).
     */
    public function recoveryEvery(PlanRequest $request): int
    {
        if ($request->preferences->recoveryWeekEvery !== null) {
            return $request->preferences->recoveryWeekEvery;
        }

        return ($request->age ?? 0) >= self::OLDER_ATHLETE_AGE ? 3 : 4;
    }

    /**
     * @param  list<PhaseType>  $phases  one per week
     * @param  list<float>  $availableHours  training hours the athlete has each week
     * @param  list<float>  $trainableShare  share of each week inside the plan (first week may start mid-week)
     * @param  int  $daysBeforeRace  days of race week before race day
     * @param  array<int, float>  $weekFactors  load adjustments by week index, e.g. a B race's mini-taper
     */
    public function plan(PlanRequest $request, array $phases, array $availableHours, array $trainableShare, int $daysBeforeRace, array $weekFactors = []): LoadPlan
    {
        $target = $this->targetCtl($request);
        $ramp = $request->preferences->maxRampRate;
        $every = $this->recoveryEvery($request);
        $taperWeeks = count(array_filter($phases, fn (PhaseType $p) => $p === PhaseType::Taper));
        $taperFactors = self::TAPER_FACTORS[$taperWeeks] ?? self::TAPER_FACTORS[1];

        $state = $request->currentLoad;
        $lastLoadingTss = null;
        $loadingStreak = 0;
        $taperIndex = 0;
        $cappedWeeks = 0;
        $peakCtl = $state->ctl;
        $raceDayState = $state;
        $weeks = [];
        $lastIndex = count($phases) - 1;

        foreach ($phases as $i => $phase) {
            $share = $trainableShare[$i];
            $isRecovery = false;

            if ($phase === PhaseType::Taper) {
                $tss = ($lastLoadingTss ?? 7 * $state->ctl) * $taperFactors[min($taperIndex++, count($taperFactors) - 1)];
            } elseif ($i > 0 && $loadingStreak >= $every - 1 && ($phases[$i + 1] ?? PhaseType::Taper) !== PhaseType::Taper) {
                $isRecovery = true;
                $loadingStreak = 0;
                $tss = ($lastLoadingTss ?? 7 * $state->ctl) * self::RECOVERY_WEEK_FACTOR;
            } else {
                $desired = max($state->ctl, min($target, $state->ctl + $ramp));
                $tss = 7 * max(0.0, $this->model->dailyTssToReach($state->ctl, $desired, 7));
                $loadingStreak++;
            }

            // Race week only trains on the days before the race, less the rest day before it.
            if ($i === $lastIndex) {
                $share = min($share, max(0, $daysBeforeRace - 1) / self::TRAINING_DAYS_PER_WEEK);
            }

            $tss *= $share;
            $cap = $availableHours[$i] * $phase->tssPerHour();

            if ($tss > $cap) {
                $tss = $cap;
                $cappedWeeks++;
            }

            if ($phase !== PhaseType::Taper && ! $isRecovery && $share > 0) {
                $lastLoadingTss = $tss / $share;
            }

            // Race-week adjustments come last so they also bite when hours are the limit.
            $weekFactor = $phase === PhaseType::Taper ? 1.0 : ($weekFactors[$i] ?? 1.0);
            $tss *= $weekFactor;

            // A race week or the easy week after a race is rest enough: restart the cadence.
            if ($weekFactor < 1.0) {
                $loadingStreak = 0;
            }

            if ($i === $lastIndex) {
                $state = $daysBeforeRace === 0 ? $state : $this->model->project($state, $tss / $daysBeforeRace, $daysBeforeRace);
                $raceDayState = $state;
            } else {
                $state = $this->model->project($state, $tss / 7, 7);
            }

            if ($phase !== PhaseType::Taper) {
                $peakCtl = max($peakCtl, $state->ctl);
            }

            $weeks[] = new WeekLoad(
                tss: round($tss, 1),
                hours: round(min($availableHours[$i], $tss / $phase->tssPerHour()), 1),
                isRecovery: $isRecovery,
                projectedCtl: round($state->ctl, 1),
            );
        }

        return new LoadPlan(
            startingCtl: round($request->currentLoad->ctl, 1),
            targetCtl: round(min($target, $peakCtl), 1),
            projectedRaceDayTsb: round($raceDayState->tsb(), 1),
            weeks: $weeks,
            warnings: $this->warnings($target, $peakCtl, $ramp, $cappedWeeks, count($phases), $raceDayState),
        );
    }

    /**
     * @return list<string>
     */
    private function warnings(float $target, float $peakCtl, float $ramp, int $cappedWeeks, int $totalWeeks, LoadState $raceDay): array
    {
        $warnings = [];

        if ($peakCtl < $target - 1) {
            $warnings[] = sprintf(
                'A peak fitness of CTL %d is not reachable by race day with a ramp of %s CTL/week and your available hours, so the plan aims for CTL %d instead.',
                round($target),
                rtrim(rtrim(number_format($ramp, 1), '0'), '.'),
                round($peakCtl),
            );
        }

        if ($cappedWeeks > 0) {
            $warnings[] = "Your available hours limit the training load in {$cappedWeeks} of {$totalWeeks} weeks.";
        }

        [$low, $high] = self::RACE_DAY_TSB;
        $tsb = $raceDay->tsb();

        if ($tsb < $low || $tsb > $high) {
            $warnings[] = sprintf('Projected race-day form is TSB %+d, outside the %+d to %+d target.', round($tsb), $low, $high);
        }

        return $warnings;
    }
}
