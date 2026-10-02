<?php

declare(strict_types=1);

namespace App\Services\Planning;

use App\Engine\Review\FeelSummary;
use App\Engine\Review\NextWeekFacts;
use App\Engine\Review\WeekFacts;
use App\Engine\Review\WeeklyReviewer;
use App\Enums\RevisionReason;
use App\Enums\WorkoutKind;
use App\Enums\WorkoutStatus;
use App\Models\Plan;
use App\Models\PlannedWorkout;
use App\Models\PlanRevision;
use App\Models\SessionFeedback;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The coach's weekly summary: gathers a week's facts from the database and
 * hands them to the WeeklyReviewer.
 */
class WeeklyReviewService
{
    /** How many of the coach's own plan changes the summary lists. */
    private const int MAX_COACH_CHANGES = 5;

    public function __construct(
        private readonly PlanProgress $progress,
        private readonly WeeklyReviewer $reviewer,
    ) {}

    /**
     * Reviews the plan week starting $monday, by default last week. Null when
     * that week is not part of the plan.
     *
     * @return array<string, mixed>|null
     */
    public function review(Plan $plan, ?CarbonImmutable $monday = null): ?array
    {
        $athlete = $plan->athlete;
        $today = $athlete->today();
        $monday = ($monday ?? $today->subWeek())->startOfWeek();
        $nextMonday = $monday->addWeek();

        $weeks = collect($this->progress->weeks($plan))->keyBy('start_date');
        $week = $weeks->get($monday->toDateString());

        if ($week === null) {
            return null;
        }

        $next = $weeks->get($nextMonday->toDateString());
        $keys = $this->keySessions($plan, $monday)->get(['title', 'date', 'status']);
        $missed = $keys->filter(fn (PlannedWorkout $w) => $w->status === WorkoutStatus::Missed
            || (in_array($w->status, WorkoutStatus::open(), true) && $w->date->lt($today)));

        $loads = $athlete->dailyLoads()
            ->whereBetween('date', self::days($monday->subDay(), 7))
            ->get(['date', 'ctl', 'tsb'])
            ->keyBy(fn ($load) => $load->date->toDateString());
        $before = $loads->get($monday->subDay()->toDateString());
        $after = $loads->get($monday->addDays(6)->toDateString());

        $facts = new WeekFacts(
            phase: $week['phase'],
            isRecovery: $week['is_recovery'],
            plannedTss: $week['planned']['tss'],
            actualTss: $week['actual']['tss'],
            plannedSeconds: $week['planned']['duration_s'],
            actualSeconds: $week['actual']['duration_s'],
            keySessions: $keys->count(),
            missedKeySessions: $missed->pluck('title')->values()->all(),
            sportTime: collect($week['by_sport'])->map(fn (array $t) => [
                'planned_s' => $t['planned_duration_s'],
                'actual_s' => $t['actual_duration_s'],
            ])->all(),
            ctlBefore: $before?->ctl,
            ctlAfter: $after?->ctl,
            tsbAfter: $after?->tsb,
            feel: $feel = $this->feel($plan, $monday, $week['actual']['activities']),
        );

        $race = $athlete->races()
            ->whereBetween('date', self::days($nextMonday, 6))
            ->orderBy('date')
            ->value('name');
        $nextFacts = $next === null ? null : new NextWeekFacts(
            phase: $next['phase'],
            isRecovery: $next['is_recovery'],
            plannedTss: $next['planned']['tss'],
            plannedSeconds: $next['planned']['duration_s'],
            keySessions: $this->keySessions($plan, $nextMonday)->count(),
            race: $race,
        );

        $review = $this->reviewer->review($facts, $nextFacts);

        return [
            'week_start' => $monday->toDateString(),
            'week_end' => $monday->addDays(6)->toDateString(),
            'phase' => $week['phase'],
            'is_recovery' => $week['is_recovery'],
            'finished' => $monday->addDays(6)->lt($today),
            'verdict' => $review->verdict,
            'headline' => $review->headline,
            'notes' => $review->notes,
            'next_week' => $review->nextWeek,
            'planned' => $week['planned'],
            'actual' => $week['actual'],
            'compliance' => $week['compliance'],
            'key_sessions' => [
                'planned' => $keys->count(),
                'done' => $keys->whereIn('status', [WorkoutStatus::Completed, WorkoutStatus::Partial])->count(),
                'missed' => $facts->missedKeySessions,
            ],
            'fitness' => [
                'ctl_before' => $before?->ctl,
                'ctl_after' => $after?->ctl,
                'tsb_after' => $after?->tsb,
            ],
            'feel' => [
                'sessions' => $feel->sessions,
                'rated' => $feel->rated,
                'rpe' => $feel->rpe,
                'muscles' => $feel->muscles,
                'breathing' => $feel->breathing,
                'energy' => $feel->energy,
                'mood' => $feel->mood,
                'pain_reports' => count($feel->painAreas),
            ],
            'coach_changes' => $this->coachChanges($plan, $athlete->utcBounds($monday, $monday)[0]),
        ];
    }

    /** RPE from which an easy session counts as having felt hard. */
    private const int EASY_FELT_HARD_RPE = 6;

    /**
     * How the week's sessions felt, from the athlete's ratings.
     */
    private function feel(Plan $plan, CarbonImmutable $monday, int $sessions): FeelSummary
    {
        $athlete = $plan->athlete;
        $ratings = $athlete->sessionFeedback()
            ->whereHas('activity', fn ($q) => $q->whereBetween('started_at', $athlete->utcBounds($monday, $monday->addDays(6))))
            ->with('activity.plannedWorkout')
            ->get();
        $average = fn (string $field): ?float => ($values = $ratings->pluck($field)->filter(fn ($v) => $v !== null))->isEmpty()
            ? null
            : round((float) $values->avg(), 1);
        $easy = WorkoutKind::Endurance->family();

        return new FeelSummary(
            sessions: $sessions,
            rated: $ratings->count(),
            rpe: $average('rpe'),
            muscles: $average('muscles'),
            breathing: $average('breathing'),
            energy: $average('energy'),
            mood: $average('mood'),
            painAreas: $ratings->where('pain', true)->map(fn (SessionFeedback $f) => (string) $f->pain_area)->values()->all(),
            easyFeltHard: $ratings->filter(fn (SessionFeedback $f) => $f->rpe >= self::EASY_FELT_HARD_RPE
                && in_array($f->activity->plannedWorkout?->kind, $easy, true))->count(),
        );
    }

    /**
     * The week's key sessions that are still part of the plan.
     *
     * @return HasMany<PlannedWorkout, Plan>
     */
    private function keySessions(Plan $plan, CarbonImmutable $monday): HasMany
    {
        return $plan->workouts()
            ->whereNull('parent_id')
            ->where('is_key', true)
            ->where('status', '!=', WorkoutStatus::Dropped)
            ->whereBetween('date', self::days($monday, 6));
    }

    /**
     * Bounds for a date column from $from through $days days later.
     *
     * @return array{0: string, 1: string}
     */
    private static function days(CarbonImmutable $from, int $days): array
    {
        return [$from->toDateString(), $from->addDays($days)->toDateString().' 23:59:59'];
    }

    /**
     * Changes the coach made to the plan since the week began, newest first.
     * The athlete's own edits are left out: they know about those.
     *
     * @return list<array{version: int, summary: string, created_at: string}>
     */
    private function coachChanges(Plan $plan, CarbonImmutable $since): array
    {
        return $plan->revisions()
            ->whereIn('reason', [RevisionReason::Adapted, RevisionReason::Regenerated])
            ->where('created_at', '>=', $since)
            ->reorder('version', 'desc')
            ->limit(self::MAX_COACH_CHANGES)
            ->get(['version', 'summary', 'created_at'])
            ->map(fn (PlanRevision $r) => [
                'version' => $r->version,
                'summary' => $r->summary,
                'created_at' => $r->created_at->toIso8601String(),
            ])
            ->all();
    }
}
