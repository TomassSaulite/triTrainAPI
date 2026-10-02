<?php

declare(strict_types=1);

namespace App\Services;

use App\Engine\Load\ActivityMetrics;
use App\Engine\Load\DailyLoadPoint;
use App\Engine\Load\LoadModel;
use App\Engine\Load\LoadState;
use App\Engine\Load\TssCalculator;
use App\Enums\Sport;
use App\Enums\TssMethod;
use App\Models\Activity;
use App\Models\Athlete;
use App\Models\DailyLoad;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;

/**
 * Bridges stored activities and the pure load engine: scores activities and
 * keeps the derived daily_load table in sync with history.
 */
class LoadService
{
    public function __construct(
        private readonly TssCalculator $calculator,
        private readonly LoadModel $model,
        private readonly ThresholdService $thresholds,
    ) {}

    /**
     * Scores an activity against the thresholds valid on the day it happened.
     * A TSS supplied by the athlete is kept as-is.
     */
    public function score(Activity $activity): Activity
    {
        if ($activity->tss !== null && $activity->tss_method === TssMethod::Provided) {
            return $activity;
        }

        $score = $this->calculator->score(
            $this->metricsFor($activity),
            $this->thresholds->setFor($activity->athlete, $activity->started_at),
        );

        $activity->forceFill([
            'tss' => $score->tss,
            'tss_method' => $score->method,
            'intensity_factor' => $score->intensityFactor,
        ])->save();

        return $activity;
    }

    /**
     * Recomputes daily load from the athlete's day containing $from (default:
     * the first activity) to their today. Earlier rows are untouched and seed
     * the recomputation.
     */
    public function rebuild(Athlete $athlete, ?DateTimeInterface $from = null): void
    {
        $firstActivity = $athlete->activities()->min('started_at');
        $today = $athlete->today();

        if ($firstActivity === null) {
            $athlete->dailyLoads()->delete();

            return;
        }

        $start = $athlete->localDate(CarbonImmutable::parse($firstActivity));

        if ($from !== null) {
            $start = $athlete->localDate($from)->max($start);
        }

        if ($start->isAfter($today)) {
            return;
        }

        $points = $this->model->series($this->dailyTss($athlete, $start), $start, $today, $this->stateBefore($athlete, $start));

        DB::transaction(function () use ($athlete, $start, $points): void {
            $athlete->dailyLoads()->whereDate('date', '>=', $start)->delete();

            foreach (array_chunk($points, 200) as $chunk) {
                DailyLoad::insert(array_map(fn (DailyLoadPoint $p) => [
                    'athlete_id' => $athlete->id,
                    'date' => $p->date->format('Y-m-d'),
                    'tss' => round($p->tss, 1),
                    'ctl' => round($p->ctl, 1),
                    'atl' => round($p->atl, 1),
                    'tsb' => round($p->tsb, 1),
                ], $chunk));
            }
        });
    }

    /**
     * Fitness and fatigue at the end of $date. Falls back to an estimate from
     * the athlete's weekly hours when there is no history yet.
     */
    public function stateOn(Athlete $athlete, DateTimeInterface $date): LoadState
    {
        $row = $athlete->dailyLoads()
            ->whereDate('date', '<=', $date)
            ->orderByDesc('date')
            ->first();

        if ($row === null) {
            return $this->estimatedState($athlete);
        }

        $gapDays = (int) $row->date->diffInDays($date);

        return $this->model->project(new LoadState($row->ctl, $row->atl), 0.0, $gapDays);
    }

    public function estimatedState(Athlete $athlete): LoadState
    {
        $ctl = $this->model->estimateCtlFromWeeklyHours($athlete->weekly_hours);

        return new LoadState($ctl, $ctl);
    }

    /**
     * The seed for a rebuild starting at $start: the stored state of the day
     * before, or, when history begins at $start, the weekly-hours estimate.
     */
    private function stateBefore(Athlete $athlete, CarbonImmutable $start): LoadState
    {
        $previous = $athlete->dailyLoads()->whereDate('date', $start->subDay())->first();

        return $previous === null
            ? $this->estimatedState($athlete)
            : new LoadState($previous->ctl, $previous->atl);
    }

    /**
     * @return array<string, float>
     */
    private function dailyTss(Athlete $athlete, CarbonImmutable $from): array
    {
        $totals = [];

        $athlete->activities()
            ->where('started_at', '>=', $athlete->utcBounds($from, $from)[0])
            ->whereNotNull('tss')
            ->get(['started_at', 'tss'])
            ->each(function (Activity $activity) use (&$totals, $athlete): void {
                $day = $athlete->localDate($activity->started_at)->toDateString();
                $totals[$day] = ($totals[$day] ?? 0.0) + $activity->tss;
            });

        return $totals;
    }

    private function metricsFor(Activity $activity): ActivityMetrics
    {
        return new ActivityMetrics(
            sport: $activity->sport,
            durationSeconds: $activity->duration_s,
            normalizedPowerWatts: $activity->np_w,
            pace: $activity->avg_pace ?? $this->derivedPace($activity),
            averageHeartRate: $activity->avg_hr,
        );
    }

    /**
     * Average pace from distance and time: s/km for runs, s/100 m for swims.
     */
    private function derivedPace(Activity $activity): ?float
    {
        if (! $activity->distance_m || $activity->duration_s === 0) {
            return null;
        }

        return match ($activity->sport) {
            Sport::Run => $activity->duration_s / ($activity->distance_m / 1000),
            Sport::Swim => $activity->duration_s / ($activity->distance_m / 100),
            default => null,
        };
    }
}
