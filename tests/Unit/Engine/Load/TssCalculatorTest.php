<?php

declare(strict_types=1);

namespace Tests\Unit\Engine\Load;

use App\Engine\Load\ActivityMetrics;
use App\Engine\Load\TssCalculator;
use App\Engine\ThresholdSet;
use App\Enums\Sport;
use App\Enums\TssMethod;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class TssCalculatorTest extends TestCase
{
    private TssCalculator $calculator;

    protected function setUp(): void
    {
        $this->calculator = new TssCalculator;
    }

    public function test_one_hour_at_ftp_scores_one_hundred(): void
    {
        $score = $this->calculator->fromPower(3600, 250, 250);

        $this->assertSame(100.0, $score->tss);
        $this->assertSame(1.0, $score->intensityFactor);
        $this->assertSame(TssMethod::Power, $score->method);
    }

    public function test_power_tss_follows_the_standard_formula(): void
    {
        // 2 h at NP 200 W, FTP 250 W: IF 0.8 -> 2 x 0.64 x 100 = 128.
        $score = $this->calculator->fromPower(7200, 200, 250);

        $this->assertSame(128.0, $score->tss);
        $this->assertSame(0.8, $score->intensityFactor);
    }

    public function test_run_intensity_compares_speeds_not_paces(): void
    {
        // Threshold 4:00/km, ran 5:00/km: IF = 240 / 300 = 0.8.
        $score = $this->calculator->fromRunPace(3600, 300, 240);

        $this->assertSame(0.8, $score->intensityFactor);
        $this->assertSame(64.0, $score->tss);
    }

    public function test_swim_intensity_is_cubed(): void
    {
        // CSS 1:40/100m, swam 2:00/100m: IF = 100 / 120.
        $score = $this->calculator->fromSwimPace(3600, 120, 100);

        $this->assertEqualsWithDelta(57.9, $score->tss, 0.05);
    }

    public function test_heart_rate_fallback_uses_lthr(): void
    {
        $score = $this->calculator->fromHeartRate(3600, 136, 170);

        $this->assertSame(TssMethod::HeartRate, $score->method);
        $this->assertSame(64.0, $score->tss);
    }

    public function test_score_prefers_power_over_heart_rate_for_rides(): void
    {
        $ride = new ActivityMetrics(Sport::Bike, 3600, normalizedPowerWatts: 200, averageHeartRate: 150);

        $score = $this->calculator->score($ride, new ThresholdSet(ftpWatts: 250, lthr: 170));

        $this->assertSame(TssMethod::Power, $score->method);
    }

    public function test_score_falls_back_to_heart_rate_without_a_power_threshold(): void
    {
        $ride = new ActivityMetrics(Sport::Bike, 3600, normalizedPowerWatts: 200, averageHeartRate: 136);

        $score = $this->calculator->score($ride, new ThresholdSet(lthr: 170));

        $this->assertSame(TssMethod::HeartRate, $score->method);
    }

    public function test_score_estimates_when_no_data_is_usable(): void
    {
        $swim = new ActivityMetrics(Sport::Swim, 1800);

        $score = $this->calculator->score($swim, new ThresholdSet);

        $this->assertSame(TssMethod::Estimated, $score->method);
        $this->assertEqualsWithDelta(0.5 * 0.75 ** 2 * 100, $score->tss, 0.05);
    }

    public function test_it_rejects_a_zero_threshold(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->calculator->fromPower(3600, 200, 0);
    }
}
