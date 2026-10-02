<?php

declare(strict_types=1);

namespace Tests\Unit\Engine\Structure;

use App\Engine\Structure\FitStepExporter;
use App\Engine\Structure\WorkoutStructure;
use App\Engine\ThresholdSet;
use PHPUnit\Framework\TestCase;

class FitStepExporterTest extends TestCase
{
    public function test_repeats_become_a_repeat_step_pointing_back_at_their_block(): void
    {
        $steps = (new FitStepExporter)->export(
            WorkoutStructure::fromArray(WorkoutStructureTest::sweetSpot()),
            new ThresholdSet(ftpWatts: 300),
        );

        $this->assertCount(5, $steps);
        $this->assertSame(['warmup', 'interval', 'recovery', 'repeat', 'cooldown'], array_column($steps, 'type'));
        $this->assertSame(1, $steps[3]['repeat_from_index']);
        $this->assertSame(3, $steps[3]['repeat_count']);
        $this->assertSame('power', $steps[1]['target_type']);
        $this->assertSame(264.0, $steps[1]['target_low']);
        $this->assertSame('warmup', $steps[0]['intensity']);
    }

    public function test_pace_targets_are_exported_as_speed(): void
    {
        $steps = (new FitStepExporter)->export(
            WorkoutStructure::fromArray(['steps' => [
                ['type' => 'steady', 'distance_m' => 400, 'target' => ['metric' => 'css_pct', 'low' => 0.8, 'high' => 1.0]],
            ]]),
            new ThresholdSet(cssSecondsPer100m: 100),
        );

        $this->assertSame('distance', $steps[0]['duration_type']);
        $this->assertSame(400, $steps[0]['duration_value']);
        $this->assertSame('speed', $steps[0]['target_type']);
        $this->assertSame(0.8, $steps[0]['target_low']);
        $this->assertSame(1.0, $steps[0]['target_high']);
    }

    public function test_unknown_thresholds_export_an_open_target_with_the_relative_one(): void
    {
        $steps = (new FitStepExporter)->export(WorkoutStructure::fromArray(WorkoutStructureTest::sweetSpot()), new ThresholdSet);

        $this->assertSame('open', $steps[0]['target_type']);
        $this->assertSame('ftp_pct', $steps[0]['relative_target']['metric']);
    }
}
