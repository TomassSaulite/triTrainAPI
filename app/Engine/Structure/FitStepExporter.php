<?php

declare(strict_types=1);

namespace App\Engine\Structure;

use App\Engine\ThresholdSet;
use App\Enums\StepType;
use App\Enums\TargetMetric;

/**
 * Flattens a structure into the step list a FIT workout file uses: one row
 * per step, with repeats expressed as a "repeat until step count" row that
 * points back at the first step of its block. Targets are absolute (watts,
 * metres per second, beats per minute) so the app only has to encode them.
 */
final class FitStepExporter
{
    public function __construct(
        private readonly StructureResolver $resolver = new StructureResolver,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function export(WorkoutStructure $structure, ThresholdSet $thresholds): array
    {
        $steps = [];
        $this->append($structure->blocks, $thresholds, $steps);

        return $steps;
    }

    /**
     * @param  list<Step|Repeat>  $blocks
     * @param  list<array<string, mixed>>  $steps
     */
    private function append(array $blocks, ThresholdSet $thresholds, array &$steps): void
    {
        foreach ($blocks as $block) {
            if ($block instanceof Repeat) {
                $first = count($steps);
                $this->append($block->steps, $thresholds, $steps);
                $steps[] = [
                    'index' => count($steps),
                    'type' => StepType::Repeat->value,
                    'duration_type' => 'repeat_until_steps_cmplt',
                    'repeat_from_index' => $first,
                    'repeat_count' => $block->count,
                ];

                continue;
            }

            $steps[] = [
                'index' => count($steps),
                'type' => $block->type->value,
                'intensity' => $this->intensity($block->type),
                'duration_type' => $block->isDistanceBased() ? 'distance' : 'time',
                'duration_value' => $block->distanceMeters ?? $block->durationSeconds,
                ...$this->target($block, $thresholds),
                'notes' => $block->note,
            ];
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function target(Step $step, ThresholdSet $thresholds): array
    {
        $target = $step->target;
        $resolved = $target === null ? null : $this->resolver->resolveTarget($target, $thresholds);

        if ($target === null || $resolved === null) {
            return ['target_type' => 'open', 'target_low' => null, 'target_high' => null, 'relative_target' => $target?->toArray()];
        }

        [$type, $low, $high] = match ($target->metric) {
            TargetMetric::FtpPct => ['power', $resolved['low'], $resolved['high']],
            TargetMetric::LthrPct => ['heart_rate', $resolved['low'], $resolved['high']],
            TargetMetric::ThresholdPacePct => ['speed', 1000 / $resolved['low'], 1000 / $resolved['high']],
            TargetMetric::CssPct => ['speed', 100 / $resolved['low'], 100 / $resolved['high']],
        };

        return [
            'target_type' => $type,
            'target_low' => round($low, $type === 'speed' ? 3 : 0),
            'target_high' => round($high, $type === 'speed' ? 3 : 0),
            'relative_target' => $target->toArray(),
        ];
    }

    private function intensity(StepType $type): string
    {
        return match ($type) {
            StepType::Warmup => 'warmup',
            StepType::Cooldown => 'cooldown',
            StepType::Recovery => 'recovery',
            StepType::Rest => 'rest',
            default => 'active',
        };
    }
}
