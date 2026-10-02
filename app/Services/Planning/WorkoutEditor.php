<?php

declare(strict_types=1);

namespace App\Services\Planning;

use App\Engine\Planning\PlanGenerator;
use App\Engine\Planning\SizedWorkout;
use App\Engine\Planning\Template;
use App\Engine\Planning\WorkoutSizer;
use App\Engine\Structure\StructureAnalyzer;
use App\Engine\Structure\WorkoutStructure;
use App\Engine\ThresholdSet;
use App\Enums\RevisionReason;
use App\Enums\WorkoutKind;
use App\Enums\WorkoutStatus;
use App\Exceptions\PlanningException;
use App\Models\PlannedWorkout;
use App\Services\ThresholdService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Changes the athlete makes to their own calendar. Each one is logged as a
 * manual revision of the plan.
 */
class WorkoutEditor
{
    /**
     * Shortest and longest a session can be made by hand.
     */
    public const int MIN_SECONDS = 600;

    public const int MAX_SECONDS = 6 * 3600;

    public function __construct(
        private readonly TemplateRepository $templates,
        private readonly ThresholdService $thresholds,
        private readonly WorkoutSizer $sizer,
        private readonly StructureAnalyzer $analyzer,
    ) {}

    public function move(PlannedWorkout $workout, CarbonImmutable $date): PlannedWorkout
    {
        $this->assertEditable($workout);
        $plan = $workout->plan;
        $today = $plan->athlete->today();

        if ($date->lessThan($today) || $date->greaterThanOrEqualTo($plan->race->date)) {
            throw new PlanningException('Workouts can be moved to any day from today until the day before the race.');
        }

        $week = $plan->weeks()->whereDate('start_date', PlanGenerator::monday($date))->first()
            ?? throw new PlanningException('That date is outside the plan.');

        $from = $workout->date->toImmutable();

        DB::transaction(function () use ($workout, $date, $week, $from, $plan): void {
            $attributes = ['date' => $date->toDateString(), 'plan_week_id' => $week->id, 'status' => WorkoutStatus::Moved];
            $workout->update($attributes);
            $workout->children()->update($attributes);

            $plan->recordRevision(
                RevisionReason::Manual,
                sprintf('Moved "%s" from %s to %s.', $workout->title, $from->format('D j M'), $date->format('D j M')),
                [['type' => 'moved', 'workout_id' => $workout->id, 'from' => $from->toDateString(), 'to' => $date->toDateString()]],
            );
        });

        return $workout->refresh();
    }

    /**
     * Workouts that could replace this one, from the athlete's library.
     *
     * @return list<Template>
     */
    public function alternatives(PlannedWorkout $workout): array
    {
        if ($workout->isBrick()) {
            return [];
        }

        $plan = $workout->plan;

        return array_values(array_filter(
            $this->templates->libraryFor($plan->athlete)->alternatives($workout->sport, $workout->kind, $plan->race?->distance, $workout->week->phase->type),
            fn (Template $t) => $t->id !== $workout->workout_template_id,
        ));
    }

    /**
     * Replaces the session with another workout, sized to the same length.
     */
    public function swap(PlannedWorkout $workout, int $templateId): PlannedWorkout
    {
        $this->assertEditable($workout, allowBrickHalf: true);
        $template = collect($this->alternatives($workout))->firstWhere('id', $templateId)
            ?? throw new PlanningException('That workout cannot replace this session.');

        $before = $workout->title;
        $sized = $this->sizer->fit($template, $workout->target_duration_s, $this->thresholds->setFor($workout->plan->athlete));

        DB::transaction(function () use ($workout, $template, $sized, $before): void {
            $workout->update([
                'workout_template_id' => $template->id,
                'title' => $template->name,
                'kind' => $template->kind,
                // A hard session swapped for an easy one stops counting as key.
                'is_key' => $workout->is_key && ($template->kind->isQuality() || $template->kind === WorkoutKind::Long),
                ...$this->sizedAttributes($sized),
            ]);

            $workout->plan->recordRevision(
                RevisionReason::Manual,
                sprintf('Swapped "%s" for "%s" on %s.', $before, $template->name, $workout->date->format('D j M')),
                [['type' => 'swapped', 'workout_id' => $workout->id, 'from' => $before, 'to' => $template->name]],
            );
        });

        $this->syncBrickParent($workout);

        return $workout->refresh();
    }

    /**
     * Makes the session shorter or longer by scaling its main set.
     */
    public function resize(PlannedWorkout $workout, int $seconds): PlannedWorkout
    {
        $this->assertEditable($workout, allowBrickHalf: true);

        if ($workout->isBrick() || $workout->structure === null) {
            throw new PlanningException('Change the length of each half of a brick separately.');
        }

        $seconds = max(self::MIN_SECONDS, min(self::MAX_SECONDS, $seconds));
        $thresholds = $this->thresholds->setFor($workout->plan->athlete);
        $template = $workout->workout_template_id === null
            ? null
            : collect($this->templates->libraryFor($workout->plan->athlete)->alternatives($workout->sport, $workout->kind))
                ->firstWhere('id', $workout->workout_template_id);

        // The athlete's choice of length wins over the template's usual range.
        $sized = $template !== null
            ? $this->sizer->fit($template->withBounds(self::MIN_SECONDS, self::MAX_SECONDS), $seconds, $thresholds)
            : $this->scaled($workout, $seconds / max(1, $workout->target_duration_s), $thresholds);

        $before = $workout->target_duration_s;

        DB::transaction(function () use ($workout, $sized, $before): void {
            $workout->update($this->sizedAttributes($sized));

            $workout->plan->recordRevision(
                RevisionReason::Manual,
                sprintf('Changed "%s" on %s from %d to %d minutes.', $workout->title, $workout->date->format('D j M'), round($before / 60), round($sized->analysis->durationSeconds / 60)),
                [['type' => 'resized', 'workout_id' => $workout->id, 'from_s' => $before, 'to_s' => $sized->analysis->durationSeconds]],
            );
        });

        $this->syncBrickParent($workout);

        return $workout->refresh();
    }

    public function skip(PlannedWorkout $workout): PlannedWorkout
    {
        $this->assertEditable($workout);

        DB::transaction(function () use ($workout): void {
            $workout->update(['status' => WorkoutStatus::Dropped]);
            $workout->children()->update(['status' => WorkoutStatus::Dropped]);

            $workout->plan->recordRevision(
                RevisionReason::Manual,
                sprintf('Skipped "%s" on %s.', $workout->title, $workout->date->format('D j M')),
                [['type' => 'dropped', 'workout_id' => $workout->id, 'date' => $workout->date->toDateString()]],
            );
        });

        return $workout->refresh();
    }

    private function scaled(PlannedWorkout $workout, float $factor, ThresholdSet $thresholds): SizedWorkout
    {
        $structure = WorkoutStructure::fromArray($workout->structure)->scaled($factor);

        return new SizedWorkout($structure, $this->analyzer->analyze($structure, $workout->sport, $thresholds));
    }

    /**
     * @return array<string, mixed>
     */
    private function sizedAttributes(SizedWorkout $sized): array
    {
        return [
            'structure' => $sized->structure->toArray(),
            'target_duration_s' => $sized->analysis->durationSeconds,
            'target_distance_m' => $sized->analysis->distanceMeters,
            'target_tss' => $sized->analysis->tss,
        ];
    }

    /**
     * A brick's totals follow its halves.
     */
    private function syncBrickParent(PlannedWorkout $workout): void
    {
        $parent = $workout->parent()->first();

        if ($parent !== null) {
            $parent->update([
                'target_duration_s' => $parent->children()->sum('target_duration_s'),
                'target_tss' => round((float) $parent->children()->sum('target_tss'), 1),
            ]);
        }
    }

    /**
     * Moving and skipping act on whole sessions; resizing also works on a brick half.
     */
    private function assertEditable(PlannedWorkout $workout, bool $allowBrickHalf = false): void
    {
        if ($workout->parent_id !== null && ! $allowBrickHalf) {
            throw new PlanningException('This is half of a brick; change the brick instead.');
        }

        if (! $workout->status->isOpen() || $workout->activity_id !== null) {
            throw new PlanningException('Only upcoming workouts that have not been done can be changed.');
        }

        if (! $workout->plan->isActive()) {
            throw new PlanningException('Only workouts in the active plan can be changed.');
        }
    }
}
