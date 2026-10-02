<?php

declare(strict_types=1);

namespace App\Engine\Structure;

use App\Engine\Load\TssCalculator;
use App\Engine\ThresholdSet;
use App\Enums\Sport;
use App\Enums\StepType;
use App\Enums\TargetMetric;

/**
 * Estimates how long a structured workout takes and how much stress it
 * produces, so the generator can size sessions against a weekly TSS target.
 */
final class StructureAnalyzer
{
    /**
     * Paces assumed for distance steps when the athlete has no threshold yet.
     */
    public const float DEFAULT_CSS_SECONDS_PER_100M = 120.0;

    public const float DEFAULT_RUN_THRESHOLD_SECONDS_PER_KM = 300.0;

    public function __construct(
        private readonly TssCalculator $calculator = new TssCalculator,
    ) {}

    public function analyze(WorkoutStructure $structure, Sport $sport, ThresholdSet $thresholds = new ThresholdSet): WorkoutAnalysis
    {
        $seconds = 0;
        $meters = 0;
        $hasDistance = false;
        $tss = 0.0;

        foreach ($structure->flatten() as $step) {
            $stepSeconds = $this->secondsFor($step, $sport, $thresholds);
            $seconds += $stepSeconds;

            if ($step->distanceMeters !== null) {
                $meters += $step->distanceMeters;
                $hasDistance = true;
            }

            if ($step->type !== StepType::Rest && $step->target !== null) {
                $tss += $this->calculator->forIntensity($sport, $stepSeconds, $step->target->midpoint());
            }
        }

        $hours = $seconds / 3600;
        $exponent = $sport === Sport::Swim ? 3 : 2;
        $intensity = $hours > 0 ? ($tss / ($hours * 100)) ** (1 / $exponent) : 0.0;

        return new WorkoutAnalysis($seconds, $hasDistance ? $meters : null, round($tss, 1), round($intensity, 3));
    }

    /**
     * Time for a step: its duration, or for distance steps the time it takes at
     * the middle of the target band (rest steps are distance-free by design).
     */
    private function secondsFor(Step $step, Sport $sport, ThresholdSet $thresholds): int
    {
        if ($step->durationSeconds !== null) {
            return $step->durationSeconds;
        }

        $fraction = $step->target?->midpoint() ?? 0.7;
        $distance = (int) $step->distanceMeters;

        $isSwim = $sport === Sport::Swim || $step->target?->metric === TargetMetric::CssPct;

        $secondsAtThreshold = $isSwim
            ? $distance / 100 * ($thresholds->cssSecondsPer100m ?? self::DEFAULT_CSS_SECONDS_PER_100M)
            : $distance / 1000 * ($thresholds->thresholdPaceSecondsPerKm ?? self::DEFAULT_RUN_THRESHOLD_SECONDS_PER_KM);

        return (int) round($secondsAtThreshold / $fraction);
    }
}
