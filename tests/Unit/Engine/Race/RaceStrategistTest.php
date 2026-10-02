<?php

declare(strict_types=1);

namespace Tests\Unit\Engine\Race;

use App\Engine\Race\BikeSpeedModel;
use App\Engine\Race\RaceStrategist;
use App\Engine\ThresholdSet;
use App\Enums\Experience;
use App\Enums\RaceDistance;
use App\Enums\Sport;
use PHPUnit\Framework\TestCase;

class RaceStrategistTest extends TestCase
{
    private static function thresholds(): ThresholdSet
    {
        return new ThresholdSet(ftpWatts: 250, thresholdPaceSecondsPerKm: 270, cssSecondsPer100m: 105);
    }

    public function test_a_half_paces_each_leg_from_the_athletes_thresholds(): void
    {
        $plan = (new RaceStrategist)->plan(RaceDistance::Half, self::thresholds(), Experience::Intermediate, 75);
        [$swim, $bike, $run] = $plan->legs;

        $this->assertSame([Sport::Swim, Sport::Bike, Sport::Run], [$swim->sport, $bike->sport, $run->sport]);

        // Bike: 72-79% of FTP, intermediate aims for the middle.
        $this->assertSame([180.0, 198.0, 189.0], [$bike->target?->easy, $bike->target?->hard, $bike->target?->target]);
        $this->assertSame('watts', $bike->target?->unit);
        // Run: 85-90% of threshold speed, so 270 s/km becomes 318 to 300 s/km.
        $this->assertSame([318.0, 300.0, 309.0], [$run->target?->easy, $run->target?->hard, $run->target?->target]);
        // Swim: 1900 m at 111 s/100 m, plus 3% for open water.
        $this->assertSame(111.0, $swim->target?->target);
        $this->assertSame((int) round(19 * 111 * 1.03), $swim->predictedSeconds);

        $this->assertSame(
            $swim->predictedSeconds + $bike->predictedSeconds + $run->predictedSeconds + 7 * 60,
            $plan->finishSeconds,
        );
        $this->assertSame([], $plan->missing);
    }

    public function test_experience_moves_the_target_within_the_range(): void
    {
        $novice = (new RaceStrategist)->plan(RaceDistance::Full, self::thresholds(), Experience::Novice, 75);
        $advanced = (new RaceStrategist)->plan(RaceDistance::Full, self::thresholds(), Experience::Advanced, 75);

        $this->assertLessThan($advanced->legs[1]->target?->target, $novice->legs[1]->target?->target);
        $this->assertGreaterThan($advanced->legs[1]->predictedSeconds, $novice->legs[1]->predictedSeconds);
    }

    public function test_missing_thresholds_leave_gaps_and_say_what_to_add(): void
    {
        $plan = (new RaceStrategist)->plan(RaceDistance::Olympic, new ThresholdSet(ftpWatts: 250), Experience::Intermediate, null);

        $this->assertNull($plan->legs[0]->target);
        $this->assertNotNull($plan->legs[1]->predictedSeconds);
        $this->assertNull($plan->finishSeconds);
        $this->assertCount(3, $plan->missing, 'CSS, run threshold and weight.');
    }

    public function test_a_marathon_is_one_run_leg_with_its_own_fueling(): void
    {
        $plan = (new RaceStrategist)->plan(RaceDistance::Marathon, self::thresholds(), Experience::Intermediate, 70);

        $this->assertCount(1, $plan->legs);
        $this->assertSame(0, $plan->transitionSeconds);
        // 87% of threshold speed: 270 / 0.87 = 310 s/km over 42.195 km.
        $this->assertSame(310.0, $plan->legs[0]->target?->target);
        $this->assertSame((int) round(42.195 * 310), $plan->finishSeconds);
        $this->assertStringContainsString('carb-load', $plan->fuelingBefore[0]);
        $this->assertStringContainsString('about 560 to 700 g', $plan->fuelingBefore[0]);
        $this->assertStringContainsString('a gel every 30 to 40 minutes', $plan->fuelingDuring[0]);
    }

    public function test_long_bike_legs_get_a_climbing_cap_and_hourly_carbs(): void
    {
        $plan = (new RaceStrategist)->plan(RaceDistance::Full, self::thresholds(), Experience::Intermediate, 75);

        $this->assertStringContainsString('on climbs stay under '.round(171 * 1.1).' W', $plan->legs[1]->advice);
        $this->assertStringStartsWith('On the bike: 75 to 90 g of carbohydrate per hour, about ', $plan->fuelingDuring[0]);
        $this->assertStringContainsString('sodium', $plan->fuelingDuring[1]);
    }

    public function test_a_sprint_needs_no_carb_load(): void
    {
        $plan = (new RaceStrategist)->plan(RaceDistance::Sprint, self::thresholds(), Experience::Intermediate, 75);

        $this->assertStringNotContainsString('carb-load', implode(' ', $plan->fuelingBefore));
        $this->assertLessThan(5400, $plan->finishSeconds);
    }

    public function test_bike_speed_rises_with_power_and_falls_with_weight(): void
    {
        $this->assertEqualsWithDelta(33.2, BikeSpeedModel::speed(190, 75) * 3.6, 0.2);
        $this->assertGreaterThan(BikeSpeedModel::speed(190, 75), BikeSpeedModel::speed(220, 75));
        $this->assertGreaterThan(BikeSpeedModel::speed(190, 95), BikeSpeedModel::speed(190, 65));
    }
}
