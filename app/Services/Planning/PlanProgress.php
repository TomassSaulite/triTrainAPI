<?php

declare(strict_types=1);

namespace App\Services\Planning;

use App\Enums\Sport;
use App\Enums\WorkoutStatus;
use App\Models\Activity;
use App\Models\Plan;
use App\Models\PlannedWorkout;
use App\Models\PlanWeek;
use Illuminate\Support\Collection;

/**
 * Planned versus done, week by week: what the app's progress screen shows.
 */
class PlanProgress
{
    /**
     * @return list<array<string, mixed>>
     */
    public function weeks(Plan $plan): array
    {
        $athlete = $plan->athlete;
        $today = $athlete->today();
        $weeks = $plan->weeks()->with('phase')->get();

        if ($weeks->isEmpty()) {
            return [];
        }

        $workouts = $plan->workouts()
            ->where('status', '!=', WorkoutStatus::Dropped)
            ->get(['id', 'plan_week_id', 'parent_id', 'sport', 'status', 'target_tss', 'target_duration_s'])
            ->groupBy('plan_week_id');

        [$from, $to] = $athlete->utcBounds($weeks->first()->start_date, $weeks->last()->endDate());
        $activities = $athlete->activities()
            ->whereBetween('started_at', [$from, $to])
            ->get(['started_at', 'sport', 'tss', 'duration_s'])
            ->groupBy(fn (Activity $a) => $athlete->localDate($a->started_at)->startOfWeek()->toDateString());

        return $weeks->map(fn (PlanWeek $week) => $this->week(
            $week,
            $workouts->get($week->id, collect()),
            $activities->get($week->start_date->toDateString(), collect()),
            $week->start_date->lte($today),
        ))->all();
    }

    /**
     * @param  Collection<int, PlannedWorkout>  $workouts
     * @param  Collection<int, Activity>  $activities
     * @return array<string, mixed>
     */
    private function week(PlanWeek $week, Collection $workouts, Collection $activities, bool $started): array
    {
        $sessions = $workouts->whereNull('parent_id');
        // Brick halves carry the per-sport time; the brick parent only groups them.
        $legs = $workouts->reject(fn (PlannedWorkout $w) => $w->sport === Sport::Brick);
        $plannedTss = round((float) $sessions->sum('target_tss'), 1);
        $actualTss = round((float) $activities->sum('tss'), 1);
        $count = fn (WorkoutStatus ...$statuses) => $sessions->filter(fn (PlannedWorkout $w) => in_array($w->status, $statuses, true))->count();

        return [
            'week_index' => $week->week_index,
            'start_date' => $week->start_date->toDateString(),
            'phase' => $week->phase->type,
            'is_recovery' => $week->is_recovery,
            'target_tss' => $week->target_tss,
            'planned' => [
                'tss' => $plannedTss,
                'duration_s' => (int) $sessions->sum('target_duration_s'),
                'sessions' => $sessions->count(),
            ],
            'actual' => [
                'tss' => $actualTss,
                'duration_s' => (int) $activities->sum('duration_s'),
                'activities' => $activities->count(),
            ],
            'sessions' => [
                'completed' => $count(WorkoutStatus::Completed),
                'partial' => $count(WorkoutStatus::Partial),
                'missed' => $count(WorkoutStatus::Missed),
                'upcoming' => $count(...WorkoutStatus::open()),
            ],
            'by_sport' => collect(Sport::disciplines())->mapWithKeys(fn (Sport $sport) => [$sport->value => [
                'planned_duration_s' => (int) $legs->where('sport', $sport)->sum('target_duration_s'),
                'actual_duration_s' => (int) $activities->where('sport', $sport)->sum('duration_s'),
            ]])->all(),
            'compliance' => $started && $plannedTss > 0 ? round($actualTss / $plannedTss, 2) : null,
        ];
    }
}
