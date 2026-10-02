<?php

declare(strict_types=1);

namespace Tests\Unit\Engine\Structure;

use App\Engine\Structure\StructureResolver;
use App\Engine\Structure\WorkoutStructure;
use App\Engine\ThresholdSet;
use PHPUnit\Framework\TestCase;

class StructureResolverTest extends TestCase
{
    public function test_power_targets_resolve_to_watts(): void
    {
        $resolved = (new StructureResolver)->resolve(
            WorkoutStructure::fromArray(WorkoutStructureTest::sweetSpot()),
            new ThresholdSet(ftpWatts: 300),
        );

        $this->assertSame(['unit' => 'watts', 'low' => 264.0, 'high' => 279.0], $resolved['steps'][1]['steps'][0]['resolved']);
    }

    public function test_pace_targets_resolve_to_slower_and_faster_paces(): void
    {
        $structure = WorkoutStructure::fromArray(['steps' => [
            ['type' => 'steady', 'duration_s' => 1200, 'target' => ['metric' => 'threshold_pace_pct', 'low' => 0.8, 'high' => 1.0]],
        ]]);

        $resolved = (new StructureResolver)->resolve($structure, new ThresholdSet(thresholdPaceSecondsPerKm: 240));

        $this->assertSame(['unit' => 's_per_km', 'low' => 300.0, 'high' => 240.0], $resolved['steps'][0]['resolved']);
    }

    public function test_unknown_thresholds_leave_targets_unresolved(): void
    {
        $resolved = (new StructureResolver)->resolve(WorkoutStructure::fromArray(WorkoutStructureTest::sweetSpot()), new ThresholdSet);

        $this->assertNull($resolved['steps'][0]['resolved']);
        $this->assertSame(['metric' => 'ftp_pct', 'low' => 0.55, 'high' => 0.65], $resolved['steps'][0]['target']);
    }
}
