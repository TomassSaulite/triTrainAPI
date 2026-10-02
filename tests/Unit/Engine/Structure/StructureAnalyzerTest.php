<?php

declare(strict_types=1);

namespace Tests\Unit\Engine\Structure;

use App\Engine\Structure\StructureAnalyzer;
use App\Engine\Structure\WorkoutStructure;
use App\Engine\ThresholdSet;
use App\Enums\Sport;
use PHPUnit\Framework\TestCase;

class StructureAnalyzerTest extends TestCase
{
    private StructureAnalyzer $analyzer;

    protected function setUp(): void
    {
        $this->analyzer = new StructureAnalyzer;
    }

    public function test_an_hour_at_threshold_is_one_hundred_tss(): void
    {
        $structure = WorkoutStructure::fromArray(['steps' => [
            ['type' => 'steady', 'duration_s' => 3600, 'target' => ['metric' => 'ftp_pct', 'low' => 0.95, 'high' => 1.05]],
        ]]);

        $analysis = $this->analyzer->analyze($structure, Sport::Bike);

        $this->assertSame(3600, $analysis->durationSeconds);
        $this->assertSame(100.0, $analysis->tss);
        $this->assertSame(1.0, $analysis->intensityFactor);
    }

    public function test_repeats_count_towards_duration_and_stress(): void
    {
        $analysis = $this->analyzer->analyze(WorkoutStructure::fromArray(WorkoutStructureTest::sweetSpot()), Sport::Bike);

        $this->assertSame(900 + 3 * 900 + 600, $analysis->durationSeconds);
        $this->assertGreaterThan(50, $analysis->tss);
        $this->assertLessThan(80, $analysis->tss);
    }

    public function test_swim_distance_is_timed_from_css_and_target(): void
    {
        $structure = WorkoutStructure::fromArray(['steps' => [
            ['type' => 'steady', 'distance_m' => 1000, 'target' => ['metric' => 'css_pct', 'low' => 0.95, 'high' => 1.05]],
        ]]);

        $analysis = $this->analyzer->analyze($structure, Sport::Swim, new ThresholdSet(cssSecondsPer100m: 100));

        $this->assertSame(1000, $analysis->durationSeconds);
        $this->assertSame(1000, $analysis->distanceMeters);
    }

    public function test_rest_steps_add_time_but_no_stress(): void
    {
        $structure = WorkoutStructure::fromArray(['steps' => [['type' => 'rest', 'duration_s' => 600]]]);

        $analysis = $this->analyzer->analyze($structure, Sport::Swim);

        $this->assertSame(600, $analysis->durationSeconds);
        $this->assertSame(0.0, $analysis->tss);
    }
}
