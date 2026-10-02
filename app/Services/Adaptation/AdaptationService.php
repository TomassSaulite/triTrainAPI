<?php

declare(strict_types=1);

namespace App\Services\Adaptation;

use App\Engine\Adaptation\Adapter;
use App\Engine\Adaptation\ChangeType;
use App\Engine\Adaptation\PlanChange;
use App\Engine\Planning\PlanGenerator;
use App\Engine\Planning\WorkoutSizer;
use App\Engine\Structure\StructureAnalyzer;
use App\Engine\Structure\WorkoutStructure;
use App\Engine\ThresholdSet;
use App\Enums\RevisionReason;
use App\Enums\Sport;
use App\Enums\WorkoutKind;
use App\Enums\WorkoutStatus;
use App\Models\Plan;
use App\Models\PlannedWorkout;
use App\Models\PlanRevision;
use App\Services\Planning\PlanService;
use App\Services\Planning\TemplateRepository;
use App\Services\ThresholdService;
use App\Services\WorkoutMatcher;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The adaptation loop: mark missed days, ask the rules what to change, apply
 * it to future unstarted workouts only, and log it as a new plan version.
 */
class AdaptationService
{
    /**
     * Recovery sessions that replace a key session are kept this short.
     */
    private const int RECOVERY_MAX_SECONDS = 2700;

    private const float RECOVERY_SHARE = 0.6;

    public function __construct(
        private readonly Adapter $adapter,
        private readonly AdaptationContextFactory $contexts,
        private readonly PlanService $plans,
        private readonly WorkoutMatcher $matcher,
        private readonly TemplateRepository $templates,
        private readonly ThresholdService $thresholds,
        private readonly StructureAnalyzer $analyzer,
        private readonly WorkoutSizer $sizer,
    ) {}

    public function adapt(Plan $plan, ?CarbonImmutable $today = null): ?PlanRevision
    {
        $today = ($today ?? $plan->athlete->today())->startOfDay();

        if (! $plan->isActive() || $today->greaterThanOrEqualTo($plan->race->date)) {
            return null;
        }

        $this->sweepMissed($plan, $today);

        $changes = $this->adapter->adapt($this->contexts->make($plan, $today));

        if ($changes === []) {
            return null;
        }

        if ($changes[0]->type === ChangeType::Regenerate) {
            $this->plans->regenerate($plan, RevisionReason::Adapted, $changes[0]->reason, $today, [$changes[0]->toArray()]);

            return $plan->revisions()->first();
        }

        return DB::transaction(function () use ($plan, $changes): PlanRevision {
            $thresholds = $this->thresholds->setFor($plan->athlete);

            foreach ($changes as $change) {
                $this->apply($plan, $change, $thresholds);
            }

            $reasons = array_values(array_unique(array_map(fn (PlanChange $c) => $c->reason, $changes)));

            return $plan->recordRevision(
                RevisionReason::Adapted,
                implode(' ', $reasons),
                array_map(fn (PlanChange $c) => $c->toArray(), $changes),
            );
        });
    }

    /**
     * Nothing by the end of the day means missed.
     */
    public function sweepMissed(Plan $plan, CarbonImmutable $today): int
    {
        $missed = $plan->workouts()
            ->open()
            ->whereNull('activity_id')
            ->whereDate('date', '<', $today)
            ->update(['status' => WorkoutStatus::Missed, 'compliance' => 0]);

        if ($missed > 0) {
            $plan->workouts()->where('sport', Sport::Brick)->whereDate('date', '<', $today)
                ->each(fn (PlannedWorkout $brick) => $this->matcher->syncBrick($brick));
        }

        return $missed;
    }

    private function apply(Plan $plan, PlanChange $change, ThresholdSet $thresholds): void
    {
        $workout = $plan->workouts()->with('children')->findOrFail($change->sessionId);

        match ($change->type) {
            ChangeType::Reschedule => $this->reschedule($plan, $workout, CarbonImmutable::instance($change->date)),
            ChangeType::Drop => $this->setStatus($workout, WorkoutStatus::Dropped),
            ChangeType::Scale => $this->scale($workout, (float) $change->factor, $thresholds),
            ChangeType::Recover => $this->recover($plan, $workout, $thresholds),
            ChangeType::Regenerate => null,
        };
    }

    /**
     * The missed session stays as history; a copy goes on the new day.
     */
    private function reschedule(Plan $plan, PlannedWorkout $missed, CarbonImmutable $date): void
    {
        $week = $plan->weeks()->whereDate('start_date', PlanGenerator::monday($date))->firstOrFail();
        $attributes = ['date' => $date->toDateString(), 'plan_week_id' => $week->id, 'status' => WorkoutStatus::Moved];

        $copy = $missed->replicate(['activity_id', 'compliance', 'status', 'date', 'plan_week_id'])->fill([
            ...$attributes,
            'rescheduled_from_id' => $missed->id,
        ]);
        $copy->save();

        foreach ($missed->children as $child) {
            $child->replicate(['activity_id', 'compliance', 'status', 'date', 'plan_week_id', 'parent_id'])
                ->fill([...$attributes, 'parent_id' => $copy->id])
                ->save();
        }
    }

    private function setStatus(PlannedWorkout $workout, WorkoutStatus $status): void
    {
        $workout->update(['status' => $status]);
        $workout->children()->update(['status' => $status]);
    }

    private function scale(PlannedWorkout $workout, float $factor, ThresholdSet $thresholds): void
    {
        if ($workout->isBrick()) {
            foreach ($workout->children as $child) {
                $this->scale($child, $factor, $thresholds);
            }

            $this->resumBrick($workout);

            return;
        }

        if ($workout->structure === null) {
            $workout->update([
                'target_duration_s' => (int) round($workout->target_duration_s * $factor),
                'target_tss' => round($workout->target_tss * $factor, 1),
            ]);

            return;
        }

        $structure = WorkoutStructure::fromArray($workout->structure)->scaled($factor);
        $analysis = $this->analyzer->analyze($structure, $workout->sport, $thresholds);

        $workout->update([
            'structure' => $structure->toArray(),
            'target_duration_s' => $analysis->durationSeconds,
            'target_distance_m' => $analysis->distanceMeters,
            'target_tss' => $analysis->tss,
        ]);
    }

    /**
     * Replaces a key session with a short recovery session of the same sport.
     * For a brick, the ride becomes the recovery spin and the run is dropped.
     */
    private function recover(Plan $plan, PlannedWorkout $workout, ThresholdSet $thresholds): void
    {
        if ($workout->isBrick()) {
            [$bike, $run] = [$workout->children->firstWhere('sport', Sport::Bike), $workout->children->firstWhere('sport', Sport::Run)];
            $run?->update(['status' => WorkoutStatus::Dropped]);

            if ($bike !== null) {
                $this->recover($plan, $bike, $thresholds);
            }

            $workout->update(['kind' => WorkoutKind::Recovery, 'is_key' => false, 'title' => 'Recovery spin (was a brick)']);
            $this->resumBrick($workout);

            return;
        }

        $phase = $workout->week->phase->type;
        $seconds = (int) min(self::RECOVERY_MAX_SECONDS, $workout->target_duration_s * self::RECOVERY_SHARE);
        $template = $this->templates->libraryFor($plan->athlete)->pick($workout->sport, WorkoutKind::Recovery, $phase);

        $sized = $template === null
            ? $this->sizer->fallback($workout->sport, WorkoutKind::Recovery, $seconds, $thresholds)
            : $this->sizer->fit($template, $seconds, $thresholds);

        $workout->update([
            'kind' => WorkoutKind::Recovery,
            'is_key' => false,
            'title' => $template->name ?? 'Recovery '.$workout->sport->value,
            'workout_template_id' => $template?->id,
            'structure' => $sized->structure->toArray(),
            'target_duration_s' => $sized->analysis->durationSeconds,
            'target_distance_m' => $sized->analysis->distanceMeters,
            'target_tss' => $sized->analysis->tss,
        ]);
    }

    private function resumBrick(PlannedWorkout $brick): void
    {
        $parts = $brick->children()->where('status', '!=', WorkoutStatus::Dropped)->get();

        $brick->update([
            'target_duration_s' => $parts->sum('target_duration_s'),
            'target_tss' => round($parts->sum('target_tss'), 1),
        ]);
    }
}
