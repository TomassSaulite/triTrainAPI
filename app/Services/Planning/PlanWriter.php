<?php

declare(strict_types=1);

namespace App\Services\Planning;

use App\Engine\Planning\GeneratedPlan;
use App\Engine\Planning\PhaseDraft;
use App\Engine\Planning\PlanGenerator;
use App\Engine\Planning\WeekDraft;
use App\Engine\Planning\WorkoutDraft;
use App\Enums\WorkoutStatus;
use App\Models\Plan;
use App\Models\PlannedWorkout;
use App\Models\PlanPhase;
use App\Models\PlanWeek;
use Carbon\CarbonImmutable;

/**
 * Writes the engine's drafts into plan_phases, plan_weeks and planned_workouts.
 */
class PlanWriter
{
    /**
     * Writes a freshly generated plan.
     */
    public function writeNew(Plan $plan, GeneratedPlan $draft): void
    {
        $phaseIds = [];

        foreach ($draft->phases as $phase) {
            $phaseIds[] = [$phase, $this->createPhase($plan, $phase)->id];
        }

        foreach ($draft->weeks as $week) {
            $row = $plan->weeks()->create([
                ...$this->weekAttributes($week, $plan),
                'plan_phase_id' => $this->phaseIdFor($week, $phaseIds),
            ]);

            $this->writeWorkouts($plan, $row, $week->workouts);
        }
    }

    /**
     * Replaces everything from $from onwards with a re-generated draft that
     * starts on $from. History before $from is left untouched.
     */
    public function writeFrom(Plan $plan, GeneratedPlan $draft, CarbonImmutable $from): void
    {
        $this->deleteOpenWorkoutsFrom($plan, $from);
        $phaseIds = $this->rebuildPhasesFrom($plan, $draft, $from);

        foreach ($draft->weeks as $week) {
            $attributes = [...$this->weekAttributes($week, $plan), 'plan_phase_id' => $this->phaseIdFor($week, $phaseIds)];

            $row = $plan->weeks()->whereDate('start_date', $week->startDate)->first() ?? $plan->weeks()->make();
            $row->fill($attributes)->save();

            $this->writeWorkouts($plan, $row, $week->workouts);
        }

        // Weeks past race week only exist when the race moved earlier.
        $plan->weeks()
            ->whereDate('start_date', '>', $plan->race->date)
            ->whereDoesntHave('workouts')
            ->delete();
    }

    /**
     * Deletes future workouts that have not been started. Completed, partial,
     * missed and dropped workouts are history and stay.
     */
    public function deleteOpenWorkoutsFrom(Plan $plan, CarbonImmutable $from): int
    {
        return $plan->workouts()
            ->whereNull('parent_id')
            ->whereDate('date', '>=', $from)
            ->whereNull('activity_id')
            ->whereIn('status', WorkoutStatus::open())
            ->whereDoesntHave('children', fn ($q) => $q->whereNotNull('activity_id')->orWhereNotIn('status', WorkoutStatus::open()))
            ->delete();
    }

    /**
     * @param  list<WorkoutDraft>  $drafts
     */
    private function writeWorkouts(Plan $plan, PlanWeek $week, array $drafts, ?PlannedWorkout $parent = null): void
    {
        foreach ($drafts as $draft) {
            $workout = new PlannedWorkout([
                'plan_week_id' => $week->id,
                'parent_id' => $parent?->id,
                'workout_template_id' => $draft->templateId,
                'date' => $draft->date->format('Y-m-d'),
                'sport' => $draft->sport,
                'kind' => $draft->kind,
                'is_key' => $draft->isKey,
                'title' => $draft->title,
                'target_duration_s' => $draft->durationSeconds,
                'target_distance_m' => $draft->distanceMeters,
                'target_tss' => $draft->tss,
                'structure' => $draft->structure?->toArray(),
                'status' => WorkoutStatus::Planned,
            ]);
            $workout->plan()->associate($plan)->save();

            $this->writeWorkouts($plan, $week, $draft->children, $workout);
        }
    }

    /**
     * Ends the phase running on $from the day before, drops phases that start
     * later, and writes the draft's phases (merging with the phase already
     * running when it continues).
     *
     * @return list<array{0: PhaseDraft, 1: int}>
     */
    private function rebuildPhasesFrom(Plan $plan, GeneratedPlan $draft, CarbonImmutable $from): array
    {
        $weekStart = CarbonImmutable::instance(PlanGenerator::monday($from));
        $current = $plan->phases()->whereDate('start_date', '<', $weekStart)->whereDate('end_date', '>=', $weekStart)->first();
        $later = $plan->phases()->whereDate('start_date', '>=', $weekStart)->pluck('id');

        $phaseIds = [];

        foreach ($draft->phases as $i => $phase) {
            if ($i === 0 && $current?->type === $phase->type) {
                $current->update(['end_date' => $phase->endDate->format('Y-m-d')]);
                $phaseIds[] = [$phase, $current->id];

                continue;
            }

            if ($i === 0) {
                $current?->update(['end_date' => $weekStart->subDay()->toDateString()]);
                $phase = new PhaseDraft($phase->type, max($weekStart, $plan->start_date->toImmutable()), $phase->endDate);
            }

            $phaseIds[] = [$phase, $this->createPhase($plan, $phase)->id];
        }

        // Reattach every week from $from to the new phases before the old ones go.
        foreach ($plan->weeks()->whereDate('start_date', '>=', $weekStart)->get() as $week) {
            $match = $this->phaseIdForDate(CarbonImmutable::instance($week->start_date), $phaseIds);

            if ($match !== null) {
                $week->update(['plan_phase_id' => $match]);
            }
        }

        PlanPhase::whereIn('id', $later)->whereDoesntHave('weeks')->delete();

        return $phaseIds;
    }

    private function createPhase(Plan $plan, PhaseDraft $phase): PlanPhase
    {
        return $plan->phases()->create([
            'type' => $phase->type,
            'start_date' => $phase->startDate->format('Y-m-d'),
            'end_date' => $phase->endDate->format('Y-m-d'),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function weekAttributes(WeekDraft $week, Plan $plan): array
    {
        $planMonday = CarbonImmutable::instance(PlanGenerator::monday($plan->start_date->toImmutable()));

        return [
            'week_index' => (int) $planMonday->diffInWeeks(CarbonImmutable::instance($week->startDate)),
            'start_date' => $week->startDate->format('Y-m-d'),
            'target_tss' => $week->targetTss,
            'target_hours' => $week->targetHours,
            'is_recovery' => $week->isRecovery,
        ];
    }

    /**
     * @param  list<array{0: PhaseDraft, 1: int}>  $phaseIds
     */
    private function phaseIdFor(WeekDraft $week, array $phaseIds): int
    {
        foreach ($phaseIds as [$phase, $id]) {
            if ($phase->type === $week->phase && $week->startDate->modify('+6 days') >= $phase->startDate && $week->startDate <= $phase->endDate) {
                return $id;
            }
        }

        return $phaseIds[array_key_last($phaseIds)][1];
    }

    /**
     * @param  list<array{0: PhaseDraft, 1: int}>  $phaseIds
     */
    private function phaseIdForDate(CarbonImmutable $weekStart, array $phaseIds): ?int
    {
        foreach ($phaseIds as [$phase, $id]) {
            if ($weekStart->addDays(6) >= $phase->startDate && $weekStart <= $phase->endDate) {
                return $id;
            }
        }

        return null;
    }
}
