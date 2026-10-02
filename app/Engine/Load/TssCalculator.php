<?php

declare(strict_types=1);

namespace App\Engine\Load;

use App\Engine\ThresholdSet;
use App\Enums\Sport;
use App\Enums\TssMethod;
use InvalidArgumentException;

/**
 * Training Stress Score: 100 = one hour at threshold.
 *
 * Every method computes an intensity factor (IF, 1.0 = threshold) and turns it
 * into stress as hours x IF^2 x 100. Swimming punishes intensity harder, so its
 * IF is cubed instead.
 */
final class TssCalculator
{
    /**
     * Intensity factor assumed when a session has no usable power, pace or heart
     * rate data: a typical aerobic session.
     */
    public const array FALLBACK_INTENSITY = [
        'swim' => 0.75,
        'bike' => 0.65,
        'run' => 0.75,
        'brick' => 0.72,
        'strength' => 0.60,
    ];

    /**
     * Scores an activity with the best data available: power, then pace, then
     * heart rate, then a duration-based estimate.
     */
    public function score(ActivityMetrics $activity, ThresholdSet $thresholds): StressScore
    {
        $seconds = $activity->durationSeconds;

        if ($activity->sport === Sport::Bike && $activity->normalizedPowerWatts !== null && $thresholds->ftpWatts !== null) {
            return $this->fromPower($seconds, $activity->normalizedPowerWatts, $thresholds->ftpWatts);
        }

        if ($activity->sport === Sport::Run && $activity->pace !== null && $thresholds->thresholdPaceSecondsPerKm !== null) {
            return $this->fromRunPace($seconds, $activity->pace, $thresholds->thresholdPaceSecondsPerKm);
        }

        if ($activity->sport === Sport::Swim && $activity->pace !== null && $thresholds->cssSecondsPer100m !== null) {
            return $this->fromSwimPace($seconds, $activity->pace, $thresholds->cssSecondsPer100m);
        }

        if ($activity->averageHeartRate !== null && $thresholds->lthr !== null) {
            return $this->fromHeartRate($seconds, $activity->averageHeartRate, $thresholds->lthr);
        }

        return $this->estimate($activity->sport, $seconds);
    }

    /**
     * TSS = (t x NP x IF) / (FTP x 3600) x 100, with IF = NP / FTP.
     */
    public function fromPower(int $seconds, float $normalizedPower, float $ftp): StressScore
    {
        $this->assertPositive($ftp, 'FTP');

        return $this->build($seconds, $normalizedPower / $ftp, exponent: 2, method: TssMethod::Power);
    }

    /**
     * Run IF compares speeds: threshold pace / actual pace (both in s/km).
     */
    public function fromRunPace(int $seconds, float $paceSecondsPerKm, float $thresholdPaceSecondsPerKm): StressScore
    {
        $this->assertPositive($paceSecondsPerKm, 'Pace');

        return $this->build($seconds, $thresholdPaceSecondsPerKm / $paceSecondsPerKm, exponent: 2, method: TssMethod::Pace);
    }

    /**
     * sTSS = IF^3 x hours x 100, with IF = CSS speed / actual speed.
     */
    public function fromSwimPace(int $seconds, float $paceSecondsPer100m, float $cssSecondsPer100m): StressScore
    {
        $this->assertPositive($paceSecondsPer100m, 'Pace');

        return $this->build($seconds, $cssSecondsPer100m / $paceSecondsPer100m, exponent: 3, method: TssMethod::Pace);
    }

    /**
     * hrTSS from average heart rate relative to LTHR. A zone-time breakdown is
     * more accurate, but average heart rate is what every source can provide.
     */
    public function fromHeartRate(int $seconds, float $averageHeartRate, float $lthr): StressScore
    {
        $this->assertPositive($lthr, 'LTHR');

        return $this->build($seconds, $averageHeartRate / $lthr, exponent: 2, method: TssMethod::HeartRate);
    }

    public function estimate(Sport $sport, int $seconds): StressScore
    {
        return $this->build($seconds, self::FALLBACK_INTENSITY[$sport->value], exponent: 2, method: TssMethod::Estimated);
    }

    /**
     * Stress for a planned block at a given intensity, using the sport's exponent.
     */
    public function forIntensity(Sport $sport, int $seconds, float $intensityFactor): float
    {
        return $this->build($seconds, $intensityFactor, $sport === Sport::Swim ? 3 : 2, TssMethod::Estimated)->tss;
    }

    private function build(int $seconds, float $intensityFactor, int $exponent, TssMethod $method): StressScore
    {
        if ($seconds < 0) {
            throw new InvalidArgumentException('Duration cannot be negative.');
        }

        $tss = ($seconds / 3600) * ($intensityFactor ** $exponent) * 100;

        return new StressScore(round($tss, 1), round($intensityFactor, 3), $method);
    }

    private function assertPositive(float $value, string $label): void
    {
        if ($value <= 0) {
            throw new InvalidArgumentException("{$label} must be greater than zero.");
        }
    }
}
