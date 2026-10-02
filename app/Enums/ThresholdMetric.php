<?php

declare(strict_types=1);

namespace App\Enums;

enum ThresholdMetric: string
{
    case CssSecondsPer100m = 'css_s_per_100m';
    case FtpWatts = 'ftp_w';
    case ThresholdPaceSecondsPerKm = 'threshold_pace_s_per_km';
    case Lthr = 'lthr';

    /**
     * The sport a metric belongs to, or null when it applies to any sport (LTHR).
     */
    public function sport(): ?Sport
    {
        return match ($this) {
            self::CssSecondsPer100m => Sport::Swim,
            self::FtpWatts => Sport::Bike,
            self::ThresholdPaceSecondsPerKm => Sport::Run,
            self::Lthr => null,
        };
    }

    /**
     * Inclusive range of plausible values, used to reject typos at the API edge.
     *
     * @return array{0: int, 1: int}
     */
    public function plausibleRange(): array
    {
        return match ($this) {
            self::CssSecondsPer100m => [50, 300],
            self::FtpWatts => [50, 600],
            self::ThresholdPaceSecondsPerKm => [150, 600],
            self::Lthr => [100, 220],
        };
    }

    /**
     * How to test for a new value, in a sentence.
     */
    public function testProtocol(): string
    {
        return match ($this) {
            self::FtpWatts => 'After a 15-minute warm-up with a few short efforts, ride 20 minutes as hard as you can hold evenly. Your FTP is about 95% of the average power.',
            self::ThresholdPaceSecondsPerKm => 'After a good warm-up, run 30 minutes as hard as you can hold evenly, alone and on a flat route. Your threshold pace is the average pace of the last 20 minutes.',
            self::CssSecondsPer100m => 'After a warm-up, swim 400 m all-out, rest 5 to 10 minutes, then 200 m all-out. Your CSS pace per 100 m is 200 divided by (400 m time minus 200 m time), in seconds.',
            self::Lthr => 'In the 30-minute run test, your threshold heart rate is the average heart rate of the last 20 minutes.',
        };
    }
}
