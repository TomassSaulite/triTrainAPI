<?php

declare(strict_types=1);

namespace App\Services;

use App\Engine\Adaptation\ActivityMatcher;
use App\Engine\Adaptation\CompletedActivity;
use App\Engine\Adaptation\ComplianceScorer;
use App\Engine\Adaptation\PlannedSession;
use App\Enums\WorkoutStatus;
use App\Models\Activity;
use App\Models\PlannedWorkout;

/**
 * Links activities to the planned workouts they fulfil and keeps each
 * workout's compliance and status in step with what was actually done.
 */
class WorkoutMatcher
{
    public function __construct(
        private readonly ActivityMatcher $matcher,
        private readonly ComplianceScorer $scorer,
    ) {}

    /**
     * Matches the activity if it is not yet linked, then scores compliance.
     */
    public function link(Activity $activity): ?PlannedWorkout
    {
        $workout = $activity->plannedWorkout()->first() ?? $this->findMatch($activity);

        if ($workout !== null) {
            $this->score($workout, $activity);
        }

        return $workout;
    }

    /**
     * Detaches an activity, e.g. before it is deleted: the workout goes back
     * to planned, or to missed when its day has passed.
     */
    public function unlink(Activity $activity): void
    {
        $workout = $activity->plannedWorkout()->first();

        if ($workout === null) {
            return;
        }

        $workout->update([
            'activity_id' => null,
            'compliance' => null,
            'status' => $workout->date->isBefore($activity->athlete->today()) ? WorkoutStatus::Missed : WorkoutStatus::Planned,
        ]);

        $this->syncParent($workout);
    }

    public function toSession(PlannedWorkout $workout): PlannedSession
    {
        return new PlannedSession(
            id: $workout->id,
            date: $workout->date->toImmutable(),
            sport: $workout->sport,
            kind: $workout->kind,
            isKey: $workout->is_key,
            durationSeconds: $workout->target_duration_s,
            tss: $workout->target_tss,
            status: $workout->status,
            activityId: $workout->activity_id,
            parentId: $workout->parent_id,
            rescheduledFromId: $workout->rescheduled_from_id,
        );
    }

    private function findMatch(Activity $activity): ?PlannedWorkout
    {
        $plan = $activity->athlete->activePlan()->first();

        if ($plan === null) {
            return null;
        }

        $day = $activity->athlete->localDate($activity->started_at);
        $candidates = $plan->workouts()
            ->whereIn('status', [...WorkoutStatus::open(), WorkoutStatus::Missed])
            ->whereNull('activity_id')
            ->where('sport', $activity->sport)
            ->whereBetween('date', [$day->subDay()->toDateString(), $day->addDay()->endOfDay()->toDateTimeString()])
            ->get()
            ->keyBy('id');

        $match = $this->matcher->match(
            new CompletedActivity($activity->id, $activity->sport, $day, $activity->duration_s, $activity->tss),
            $candidates->map($this->toSession(...))->values()->all(),
        );

        return $match === null ? null : $candidates[$match->id];
    }

    private function score(PlannedWorkout $workout, Activity $activity): void
    {
        $compliance = $this->scorer->score($activity->tss ?? 0.0, $workout->target_tss);

        $wasMissed = $workout->status === WorkoutStatus::Missed;

        $workout->update([
            'activity_id' => $activity->id,
            'compliance' => $compliance->ratio,
            'status' => $compliance->status,
        ]);

        // It was done after all, so the make-up session the coach added is no longer needed.
        if ($wasMissed) {
            PlannedWorkout::where('rescheduled_from_id', $workout->id)->open()->whereNull('activity_id')->update(['status' => WorkoutStatus::Dropped]);
        }

        $this->syncParent($workout);
    }

    private function syncParent(PlannedWorkout $workout): void
    {
        $parent = $workout->parent()->first();

        if ($parent !== null) {
            $this->syncBrick($parent);
        }
    }

    /**
     * A brick is done when both halves are; its compliance is their combined load.
     */
    public function syncBrick(PlannedWorkout $parent): void
    {
        $children = $parent->children()->get();

        if ($children->every(fn (PlannedWorkout $c) => $c->status->isOpen())) {
            $parent->update(['status' => WorkoutStatus::Planned, 'compliance' => null]);

            return;
        }

        if ($children->contains(fn (PlannedWorkout $c) => $c->status->isOpen())) {
            return;
        }

        $compliance = $this->scorer->combine($children->map(fn (PlannedWorkout $c) => [
            'actual' => ($c->compliance ?? 0.0) * $c->target_tss,
            'planned' => $c->target_tss,
        ])->all());

        $parent->update(['status' => $compliance->status, 'compliance' => $compliance->ratio]);
    }
}
