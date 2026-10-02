<?php

declare(strict_types=1);

namespace Tests\Unit\Engine\Planning;

use App\Engine\Planning\CoachPreferences;
use App\Engine\Planning\SportSplitter;
use App\Enums\RaceDistance;
use App\Enums\Sport;
use PHPUnit\Framework\TestCase;

class SportSplitterTest extends TestCase
{
    public function test_half_distance_defaults_follow_the_design_doc(): void
    {
        $shares = (new SportSplitter)->shares(RaceDistance::Half, new CoachPreferences);

        $this->assertEqualsWithDelta(1.0, array_sum($shares), 1e-9);
        $this->assertGreaterThanOrEqual(0.15, $shares['swim']);
        $this->assertLessThanOrEqual(0.20, $shares['swim']);
        $this->assertGreaterThanOrEqual(0.45, $shares['bike']);
        $this->assertGreaterThanOrEqual(0.30, $shares['run']);
    }

    public function test_time_shifts_toward_the_weakest_discipline(): void
    {
        $splitter = new SportSplitter;
        $default = $splitter->shares(RaceDistance::Half, new CoachPreferences);
        $shifted = $splitter->shares(RaceDistance::Half, new CoachPreferences, Sport::Swim);

        $this->assertEqualsWithDelta($default['swim'] + SportSplitter::WEAKNESS_SHIFT, $shifted['swim'], 1e-9);
        $this->assertLessThan($default['bike'], $shifted['bike']);
        $this->assertEqualsWithDelta(1.0, array_sum($shifted), 1e-9);
    }

    public function test_an_explicit_split_is_used_as_is(): void
    {
        $share = ['swim' => 0.3, 'bike' => 0.4, 'run' => 0.3];

        $this->assertSame($share, (new SportSplitter)->shares(RaceDistance::Half, new CoachPreferences(sportShare: $share), Sport::Run));
    }
}
