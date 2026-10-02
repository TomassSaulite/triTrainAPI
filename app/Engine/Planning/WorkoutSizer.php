<?php

declare(strict_types=1);

namespace App\Engine\Planning;

use App\Engine\Structure\StructureAnalyzer;
use App\Engine\Structure\WorkoutStructure;
use App\Engine\ThresholdSet;
use App\Enums\Sport;
use App\Enums\WorkoutKind;

/**
 * Scales a template's main set until the session lasts about as long as its
 * slot, staying inside the template's own minimum and maximum length.
 */
final class WorkoutSizer
{
    /** The shortest session the planner writes. */
    public const int MIN_SECONDS = 600;

    private const int MAX_ITERATIONS = 6;

    private const float TOLERANCE = 0.04;

    /**
     * Intensity of the plain steady session used when the library has nothing for a slot.
     */
    private const array FALLBACK_INTENSITY = [
        'recovery' => [0.50, 0.60],
        'endurance' => [0.65, 0.75],
        'long' => [0.65, 0.75],
        'technique' => [0.65, 0.75],
        'tempo' => [0.76, 0.85],
        'threshold' => [0.90, 0.95],
        'vo2' => [0.90, 0.95],
        'race_pace' => [0.78, 0.85],
    ];

    public function __construct(
        private readonly StructureAnalyzer $analyzer = new StructureAnalyzer,
    ) {}

    public function fit(Template $template, int $targetSeconds, ThresholdSet $thresholds, ?int $maxSeconds = null): SizedWorkout
    {
        $upper = max(self::MIN_SECONDS, min($template->maxSeconds, $maxSeconds ?? PHP_INT_MAX));
        // A day's time limit beats the template's usual minimum: a shorter session beats none.
        // Nothing is sized below MIN_SECONDS, even when a day has no time left over.
        $target = max(self::MIN_SECONDS, min($template->minSeconds, $upper), min($upper, $targetSeconds));

        return $this->scaleTo($template->structure, $template->sport, $target, $thresholds);
    }

    /**
     * A single steady block for slots the library cannot fill.
     */
    public function fallback(Sport $sport, WorkoutKind $kind, int $seconds, ThresholdSet $thresholds): SizedWorkout
    {
        [$low, $high] = self::FALLBACK_INTENSITY[$kind->value];

        $structure = WorkoutStructure::fromArray(['steps' => [[
            'type' => 'steady',
            'duration_s' => max(self::MIN_SECONDS, (int) (round($seconds / 60) * 60)),
            'target' => ['metric' => $sport->primaryTargetMetric()->value, 'low' => $low, 'high' => $high],
        ]]]);

        return new SizedWorkout($structure, $this->analyzer->analyze($structure, $sport, $thresholds));
    }

    private function scaleTo(WorkoutStructure $base, Sport $sport, int $target, ThresholdSet $thresholds): SizedWorkout
    {
        $factor = 1.0;
        $best = null;

        for ($i = 0; $i < self::MAX_ITERATIONS; $i++) {
            $structure = $factor === 1.0 ? $base : $base->scaled($factor);
            $analysis = $this->analyzer->analyze($structure, $sport, $thresholds);
            $error = abs($analysis->durationSeconds - $target) / $target;

            if ($best === null || $error < $best[0]) {
                $best = [$error, new SizedWorkout($structure, $analysis)];
            }

            if ($error <= self::TOLERANCE || $analysis->durationSeconds === 0) {
                break;
            }

            $factor = max(0.1, min(8.0, $factor * $target / $analysis->durationSeconds));
        }

        return $best[1];
    }
}
