<?php

declare(strict_types=1);

namespace App\Services\Adaptation;

use App\Engine\Adaptation\AdaptationContext;
use App\Engine\Adaptation\DayRecord;
use App\Engine\Adaptation\FeelRecord;
use App\Engine\Adaptation\WeekLoadRecord;
use App\Enums\RevisionReason;
use App\Enums\Sport;
use App\Enums\WorkoutStatus;
use App\Models\Activity;
use App\Models\Plan;
use App\Models\PlannedWorkout;
use App\Models\PlanRevision;
use App\Models\PlanWeek;
use App\Models\SessionFeedback;
use App\Services\LoadService;
use App\Services\WorkoutMatcher;
use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;

/**
 * Reads what the adaptation rules need from the database.
 */
class AdaptationContextFactory
{
    /**
     * How far back to look for missed days and earlier adaptations.
     */
    public const int LOOKBACK_DAYS = 14;

    public function __construct(
        private readonly LoadService $load,
        private readonly WorkoutMatcher $matcher,
    ) {}

    public function make(Plan $plan, CarbonImmutable $today): AdaptationContext
    {
        $athlete = $plan->athlete;
        $monday = $today->startOfWeek();
        $nextMonday = $monday->addWeek();

        return new AdaptationContext(
            today: $today,
            raceDate: $plan->race->date->toImmutable(),
            preferences: $athlete->prefs,
            currentCtl: $this->load->stateOn($athlete, $today)->ctl,
            thisWeek: $this->sessions($plan, $monday, $monday->addDays(6)),
            nextWeek: $this->sessions($plan, $nextMonday, $nextMonday->addDays(6)),
            recentDays: $this->recentDays($plan, $today),
            recentTsb: $athlete->dailyLoads()
                ->whereDate('date', '<=', $today)
                ->orderByDesc('date')
                ->limit(3)
                ->pluck('tsb')
                ->reverse()
                ->values()
                ->all(),
            completedWeeks: $this->completedWeeks($plan, $monday),
            unavailableDates: $athlete->availabilityOverrides()
                ->where('available_minutes', 0)
                ->whereBetween('date', [$today->toDateString(), $nextMonday->addDays(6)->endOfDay()->toDateTimeString()])
                ->get()
                ->map(fn ($o) => $o->date->toDateString())
                ->all(),
            appliedKeys: $this->appliedKeys($plan, $today),
            recentFeel: $this->recentFeel($plan, $today),
        );
    }

    /**
     * How the sessions of the last week felt, oldest first.
     *
     * @return list<FeelRecord>
     */
    private function recentFeel(Plan $plan, CarbonImmutable $today): array
    {
        $athlete = $plan->athlete;

        return $athlete->sessionFeedback()
            ->join('activities', 'activities.id', '=', 'session_feedback.activity_id')
            ->whereBetween('activities.started_at', $athlete->utcBounds($today->subWeek(), $today))
            ->orderBy('activities.started_at')
            ->get(['session_feedback.*', 'activities.started_at', 'activities.sport'])
            ->map(fn (SessionFeedback $f) => new FeelRecord(
                id: $f->id,
                date: $athlete->localDate(CarbonImmutable::parse($f->getAttribute('started_at'))),
                sport: Sport::from($f->getAttribute('sport')),
                rpe: $f->rpe,
                muscles: $f->muscles,
                breathing: $f->breathing,
                energy: $f->energy,
                mood: $f->mood,
                pain: $f->pain,
                painArea: $f->pain_area,
            ))
            ->all();
    }

    /**
     * @return list<\App\Engine\Adaptation\PlannedSession>
     */
    private function sessions(Plan $plan, CarbonImmutable $from, CarbonImmutable $to): array
    {
        return $plan->workouts()
            ->whereNull('parent_id')
            ->whereBetween('date', [$from->toDateString(), $to->endOfDay()->toDateTimeString()])
            ->orderBy('date')
            ->orderBy('id')
            ->get()
            ->map(fn (PlannedWorkout $w) => $this->matcher->toSession($w))
            ->all();
    }

    /**
     * @return list<DayRecord>
     */
    private function recentDays(Plan $plan, CarbonImmutable $today): array
    {
        $from = $today->subDays(self::LOOKBACK_DAYS);
        $yesterday = $today->subDay();

        $workouts = $plan->workouts()
            ->whereNull('parent_id')
            ->where('status', '!=', WorkoutStatus::Dropped)
            ->whereBetween('date', [$from->toDateString(), $yesterday->endOfDay()->toDateTimeString()])
            ->get(['date', 'status'])
            ->groupBy(fn (PlannedWorkout $w) => $w->date->toDateString());

        $athlete = $plan->athlete;
        $activities = $athlete->activities()
            ->whereBetween('started_at', $athlete->utcBounds($from, $yesterday))
            ->get(['started_at'])
            ->countBy(fn (Activity $a) => $athlete->localDate($a->started_at)->toDateString());

        $days = [];

        foreach (CarbonPeriod::create($from, $yesterday) as $day) {
            $key = $day->toDateString();
            $planned = $workouts->get($key, collect());

            $days[] = new DayRecord(
                date: $day->toDateTimeImmutable(),
                plannedSessions: $planned->count(),
                missedSessions: $planned->filter(fn (PlannedWorkout $w) => $w->status === WorkoutStatus::Missed)->count(),
                activities: $activities->get($key, 0),
            );
        }

        return $days;
    }

    /**
     * Planned versus actual load for the last two finished weeks of the plan.
     *
     * @return list<WeekLoadRecord>
     */
    private function completedWeeks(Plan $plan, CarbonImmutable $monday): array
    {
        return $plan->weeks()
            ->whereDate('start_date', '<', $monday)
            ->reorder('start_date', 'desc')
            ->limit(2)
            ->withSum(['workouts as planned_tss' => fn ($q) => $q->whereNull('parent_id')->where('status', '!=', WorkoutStatus::Dropped)], 'target_tss')
            ->get()
            ->reverse()
            ->map(function (PlanWeek $week) use ($plan): WeekLoadRecord {
                $start = $week->start_date->toImmutable();
                $actual = $plan->athlete->activities()
                    ->whereBetween('started_at', $plan->athlete->utcBounds($start, $start->addDays(6)))
                    ->sum('tss');

                return new WeekLoadRecord($start, (float) $week->getAttribute('planned_tss'), (float) $actual);
            })
            ->values()
            ->all();
    }

    /**
     * Keys of the adaptations already made recently, so each fires once.
     *
     * @return list<string>
     */
    private function appliedKeys(Plan $plan, CarbonImmutable $today): array
    {
        return $plan->revisions()
            ->where('reason', RevisionReason::Adapted)
            ->where('created_at', '>=', $today->subDays(self::LOOKBACK_DAYS + 7))
            ->get()
            ->flatMap(fn (PlanRevision $r) => array_column($r->changes, 'key'))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }
}
