<?php

declare(strict_types=1);

namespace Tests\Unit\Engine\Planning;

use App\Engine\Planning\WorkoutSizer;
use App\Engine\Structure\StructureAnalyzer;
use App\Engine\ThresholdSet;
use App\Enums\PhaseType;
use App\Enums\Sport;
use App\Enums\WorkoutKind;
use PHPUnit\Framework\TestCase;
use Tests\Support\SystemLibrary;

class WorkoutSizerTest extends TestCase
{
    private static function sizer(): WorkoutSizer
    {
        return new WorkoutSizer(new StructureAnalyzer);
    }

    public function test_a_day_limit_below_the_templates_minimum_wins(): void
    {
        $template = SystemLibrary::load()->pick(Sport::Bike, WorkoutKind::Endurance, PhaseType::Base);
        $this->assertNotNull($template);
        $this->assertGreaterThan(1800, $template->minSeconds);

        $sized = self::sizer()->fit($template, 5400, new ThresholdSet(ftpWatts: 250), maxSeconds: 1800);

        $this->assertLessThanOrEqual(1800 * 1.05, $sized->analysis->durationSeconds);
    }

    public function test_no_time_left_still_gives_a_short_session_instead_of_crashing(): void
    {
        $template = SystemLibrary::load()->pick(Sport::Bike, WorkoutKind::Long, PhaseType::Build);
        $this->assertNotNull($template);

        foreach ([0, -600] as $maxSeconds) {
            $sized = self::sizer()->fit($template, 7200, new ThresholdSet(ftpWatts: 250), $maxSeconds);
            $this->assertGreaterThan(0, $sized->analysis->durationSeconds);
        }
    }
}
