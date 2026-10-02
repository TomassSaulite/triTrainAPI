<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\WorkoutKind;
use App\Enums\WorkoutStatus;
use App\Models\Athlete;
use App\Models\PlannedWorkout;
use App\Models\Race;
use App\Support\IcsCalendar;

/**
 * The athlete's training plan as an iCalendar feed: every planned session
 * and race as an all-day event, from two weeks back to the end of the plan.
 */
class CalendarFeed
{
    private const int DAYS_BACK = 14;

    public function render(Athlete $athlete): string
    {
        $today = $athlete->today();
        $calendar = new IcsCalendar('TriTrain training plan');
        $plan = $athlete->activePlan()->first();

        if ($plan !== null) {
            $plan->workouts()
                ->whereNull('parent_id')
                ->where('status', '!=', WorkoutStatus::Dropped)
                ->whereDate('date', '>=', $today->subDays(self::DAYS_BACK))
                ->orderBy('date')->orderBy('id')
                ->get()
                ->each(fn (PlannedWorkout $w) => $calendar->addDay(
                    "workout-{$w->id}@tritrain",
                    $w->date,
                    $this->summary($w),
                    $this->description($w),
                    $w->updated_at,
                ));
        }

        $athlete->races()
            ->whereDate('date', '>=', $today->subDays(self::DAYS_BACK))
            ->get()
            ->each(fn (Race $r) => $calendar->addDay(
                "race-{$r->id}@tritrain",
                $r->date,
                "{$r->priority->value} race: {$r->name}",
                'Race day. Your pacing and fuelling plan is on the race\'s Race plan page in TriTrain.',
                $r->updated_at,
            ));

        return $calendar->render();
    }

    private function summary(PlannedWorkout $w): string
    {
        $prefix = match ($w->status) {
            WorkoutStatus::Completed => '✓ ',
            WorkoutStatus::Missed => 'Missed: ',
            default => '',
        };

        return sprintf('%s%s: %s (%s)', $prefix, ucfirst($w->sport->value), $w->title, self::duration($w->target_duration_s));
    }

    private function description(PlannedWorkout $w): string
    {
        return implode("\n", array_filter([
            $w->is_key ? 'Key session: the one to protect this week.' : null,
            ucfirst(str_replace('_', ' ', $w->kind->value)).' · '.self::duration($w->target_duration_s).' · '.round($w->target_tss).' TSS',
            $w->kind === WorkoutKind::Recovery ? 'Keep it easy: easier than you think it should be.' : null,
            'Steps and targets are in TriTrain.',
        ]));
    }

    private static function duration(int $seconds): string
    {
        $minutes = (int) round($seconds / 60);

        return $minutes < 60 ? "{$minutes} min" : sprintf('%d h %02d', intdiv($minutes, 60), $minutes % 60);
    }
}
