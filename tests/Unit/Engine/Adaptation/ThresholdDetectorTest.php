<?php

declare(strict_types=1);

namespace Tests\Unit\Engine\Adaptation;

use App\Engine\Adaptation\ThresholdDetector;
use App\Engine\ThresholdSet;
use App\Enums\Sport;
use App\Enums\ThresholdMetric;
use PHPUnit\Framework\TestCase;

class ThresholdDetectorTest extends TestCase
{
    public function test_a_strong_twenty_minutes_suggests_a_higher_ftp(): void
    {
        $suggestions = (new ThresholdDetector)->detect(Sport::Bike, 5400, 290, null, new ThresholdSet(ftpWatts: 250));

        $this->assertCount(1, $suggestions);
        $this->assertSame(ThresholdMetric::FtpWatts, $suggestions[0]->metric);
        $this->assertSame(276.0, $suggestions[0]->suggestedValue);
        $this->assertSame(250.0, $suggestions[0]->currentValue);
    }

    public function test_ordinary_rides_suggest_nothing(): void
    {
        $this->assertSame([], (new ThresholdDetector)->detect(Sport::Bike, 5400, 262, null, new ThresholdSet(ftpWatts: 250)));
    }

    public function test_an_unknown_ftp_is_suggested_from_any_twenty_minute_power(): void
    {
        $suggestions = (new ThresholdDetector)->detect(Sport::Bike, 3600, 200, null, new ThresholdSet);

        $this->assertSame(190.0, $suggestions[0]->suggestedValue);
        $this->assertNull($suggestions[0]->currentValue);
    }

    public function test_a_long_run_faster_than_threshold_suggests_a_new_pace(): void
    {
        $suggestions = (new ThresholdDetector)->detect(Sport::Run, 2400, null, 258.0, new ThresholdSet(thresholdPaceSecondsPerKm: 270));

        $this->assertSame(ThresholdMetric::ThresholdPaceSecondsPerKm, $suggestions[0]->metric);
        $this->assertSame(258.0, $suggestions[0]->suggestedValue);
        $this->assertStringContainsString('4:18/km for 40 minutes', $suggestions[0]->rationale);
    }

    public function test_short_fast_runs_do_not_count(): void
    {
        $this->assertSame([], (new ThresholdDetector)->detect(Sport::Run, 1200, null, 240.0, new ThresholdSet(thresholdPaceSecondsPerKm: 270)));
    }
}
