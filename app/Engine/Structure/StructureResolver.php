<?php

declare(strict_types=1);

namespace App\Engine\Structure;

use App\Engine\ThresholdSet;
use App\Enums\TargetMetric;

/**
 * Turns relative targets into the athlete's real numbers for display and watch
 * export. Because only this step knows absolute values, a new threshold test
 * updates every future workout automatically.
 */
final class StructureResolver
{
    /**
     * @return array{steps: list<array<string, mixed>>}
     */
    public function resolve(WorkoutStructure $structure, ThresholdSet $thresholds): array
    {
        return ['steps' => $this->resolveBlocks($structure->blocks, $thresholds)];
    }

    /**
     * Absolute bounds for a target, or null when the threshold is unknown.
     *
     * @return array{unit: string, low: float, high: float}|null
     */
    public function resolveTarget(Target $target, ThresholdSet $thresholds): ?array
    {
        return match ($target->metric) {
            TargetMetric::FtpPct => $this->scale($thresholds->ftpWatts, $target, 'watts', 0),
            TargetMetric::LthrPct => $this->scale($thresholds->lthr, $target, 'bpm', 0),
            TargetMetric::ThresholdPacePct => $this->pace($thresholds->thresholdPaceSecondsPerKm, $target, 's_per_km'),
            TargetMetric::CssPct => $this->pace($thresholds->cssSecondsPer100m, $target, 's_per_100m'),
        };
    }

    /**
     * @param  list<Step|Repeat>  $blocks
     * @return list<array<string, mixed>>
     */
    private function resolveBlocks(array $blocks, ThresholdSet $thresholds): array
    {
        return array_map(function (Step|Repeat $block) use ($thresholds): array {
            if ($block instanceof Repeat) {
                return [...$block->toArray(), 'steps' => $this->resolveBlocks($block->steps, $thresholds)];
            }

            return [
                ...$block->toArray(),
                'resolved' => $block->target === null ? null : $this->resolveTarget($block->target, $thresholds),
            ];
        }, $blocks);
    }

    /**
     * @return array{unit: string, low: float, high: float}|null
     */
    private function scale(?float $threshold, Target $target, string $unit, int $precision): ?array
    {
        if ($threshold === null) {
            return null;
        }

        return [
            'unit' => $unit,
            'low' => round($threshold * $target->low, $precision),
            'high' => round($threshold * $target->high, $precision),
        ];
    }

    /**
     * Pace targets are fractions of threshold speed, so pace = threshold pace / fraction.
     * "low" stays the easier end of the band: the slower pace (more seconds).
     *
     * @return array{unit: string, low: float, high: float}|null
     */
    private function pace(?float $thresholdPace, Target $target, string $unit): ?array
    {
        if ($thresholdPace === null) {
            return null;
        }

        return [
            'unit' => $unit,
            'low' => round($thresholdPace / $target->low, 1),
            'high' => round($thresholdPace / $target->high, 1),
        ];
    }
}
