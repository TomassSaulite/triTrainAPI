<?php

declare(strict_types=1);

namespace Tests\Unit\Engine\Adaptation;

use App\Engine\Adaptation\ComplianceScorer;
use App\Enums\WorkoutStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ComplianceScorerTest extends TestCase
{
    /**
     * @return iterable<string, array{float, WorkoutStatus}>
     */
    public static function thresholds(): iterable
    {
        yield 'well over' => [120.0, WorkoutStatus::Completed];
        yield 'exactly 80%' => [80.0, WorkoutStatus::Completed];
        yield 'just under 80%' => [79.0, WorkoutStatus::Partial];
        yield 'exactly 50%' => [50.0, WorkoutStatus::Partial];
        yield 'under half' => [49.0, WorkoutStatus::Missed];
    }

    #[DataProvider('thresholds')]
    public function test_status_follows_the_compliance_bands(float $actual, WorkoutStatus $expected): void
    {
        $this->assertSame($expected, (new ComplianceScorer)->score($actual, 100.0)->status);
    }

    public function test_ratio_is_rounded_to_two_places(): void
    {
        $this->assertSame(0.67, (new ComplianceScorer)->score(2.0, 3.0)->ratio);
    }

    public function test_brick_halves_combine_by_total_load(): void
    {
        $compliance = (new ComplianceScorer)->combine([
            ['actual' => 100.0, 'planned' => 100.0],
            ['actual' => 0.0, 'planned' => 30.0],
        ]);

        $this->assertSame(WorkoutStatus::Partial, $compliance->status);
        $this->assertSame(0.77, $compliance->ratio);
    }
}
