<?php

declare(strict_types=1);

namespace App\Services\Planning;

use App\Engine\Structure\FitStepExporter;
use App\Engine\Structure\StructureResolver;
use App\Engine\Structure\WorkoutStructure;
use App\Models\PlannedWorkout;
use App\Services\ThresholdService;

/**
 * Resolves stored, threshold-relative workouts into the athlete's numbers.
 */
class WorkoutPresenter
{
    public function __construct(
        private readonly ThresholdService $thresholds,
        private readonly StructureResolver $resolver,
        private readonly FitStepExporter $exporter,
    ) {}

    /**
     * @return array<string, mixed>|null
     */
    public function resolved(PlannedWorkout $workout): ?array
    {
        if ($workout->structure === null) {
            return null;
        }

        return $this->resolver->resolve(
            WorkoutStructure::fromArray($workout->structure),
            $this->thresholds->setFor($workout->plan->athlete),
        );
    }

    /**
     * A FIT-ready description of the workout (or of each half of a brick).
     *
     * @return array<string, mixed>
     */
    public function export(PlannedWorkout $workout): array
    {
        $thresholds = $this->thresholds->setFor($workout->plan->athlete);
        $parts = $workout->isBrick() ? $workout->children()->orderBy('id')->get()->all() : [$workout];

        return [
            'id' => $workout->id,
            'date' => $workout->date->toDateString(),
            'title' => $workout->title,
            'sport' => $workout->sport,
            'workouts' => array_map(fn (PlannedWorkout $part) => [
                'id' => $part->id,
                'name' => $part->title,
                'sport' => $part->sport,
                'estimated_duration_s' => $part->target_duration_s,
                'steps' => $part->structure === null
                    ? []
                    : $this->exporter->export(WorkoutStructure::fromArray($part->structure), $thresholds),
            ], $parts),
        ];
    }
}
