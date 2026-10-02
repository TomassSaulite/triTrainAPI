<?php

declare(strict_types=1);

namespace Tests\Unit\Engine\Planning;

use App\Engine\Planning\PhasePlanner;
use App\Enums\PhaseType;
use App\Enums\RaceDistance;
use PHPUnit\Framework\TestCase;

class PhasePlannerTest extends TestCase
{
    /**
     * @param  list<PhaseType>  $sequence
     * @return array<string, int>
     */
    private static function phaseCounts(array $sequence): array
    {
        return array_count_values(array_map(fn (PhaseType $p) => $p->value, $sequence));
    }

    public function test_a_long_half_plan_has_a_one_week_taper_and_three_peak_weeks(): void
    {
        $sequence = (new PhasePlanner)->sequence(24, RaceDistance::Half);

        $this->assertSame(['base' => 12, 'build' => 8, 'peak' => 3, 'taper' => 1], self::phaseCounts($sequence));
        $this->assertSame(PhaseType::Base, $sequence[0]);
        $this->assertSame(PhaseType::Taper, $sequence[23]);
    }

    public function test_a_full_distance_plan_tapers_for_two_weeks(): void
    {
        $sequence = (new PhasePlanner)->sequence(30, RaceDistance::Full);

        $this->assertSame(2, self::phaseCounts($sequence)['taper']);
    }

    public function test_short_plans_get_two_peak_weeks(): void
    {
        $sequence = (new PhasePlanner)->sequence(10, RaceDistance::Half);

        $this->assertSame(['base' => 4, 'build' => 3, 'peak' => 2, 'taper' => 1], self::phaseCounts($sequence));
    }

    public function test_phases_are_in_calendar_order(): void
    {
        $sequence = (new PhasePlanner)->sequence(16, RaceDistance::Olympic);
        $order = array_map(fn (PhaseType $p) => array_search($p, PhaseType::cases(), true), $sequence);

        $sorted = $order;
        sort($sorted);
        $this->assertSame($sorted, $order);
    }

    public function test_a_plan_shorter_than_the_taper_is_all_taper(): void
    {
        $this->assertSame([PhaseType::Taper], (new PhasePlanner)->sequence(1, RaceDistance::Full));
    }

    public function test_races_early_in_the_week_start_the_taper_a_week_sooner(): void
    {
        $planner = new PhasePlanner;

        $this->assertSame(2, self::phaseCounts($planner->sequence(20, RaceDistance::Half, daysBeforeRace: 0))['taper']);
        $this->assertSame(1, self::phaseCounts($planner->sequence(20, RaceDistance::Half, daysBeforeRace: 6))['taper']);
        $this->assertSame(3, self::phaseCounts($planner->sequence(20, RaceDistance::Full, daysBeforeRace: 2))['taper']);
    }
}
