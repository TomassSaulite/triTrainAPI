<?php

declare(strict_types=1);

namespace App\Engine\Adaptation;

use App\Engine\ThresholdSet;
use App\Enums\Sport;
use App\Enums\ThresholdMetric;

/**
 * Spots efforts suggesting a threshold has moved, e.g. best 20-minute power
 * x 0.95 above the current FTP. Thresholds are never changed silently: these
 * become suggestions the athlete accepts or dismisses.
 */
final class ThresholdDetector
{
    /**
     * Share of 20-minute power sustainable for an hour.
     */
    public const float TWENTY_MINUTE_FACTOR = 0.95;

    /**
     * Ignore improvements smaller than this; they are within test noise.
     */
    public const float MIN_IMPROVEMENT = 0.01;

    /**
     * Continuous running at least this long at an average pace faster than
     * threshold is itself a threshold-level effort.
     */
    public const int MIN_RUN_SECONDS = 1800;

    /**
     * @return list<ThresholdSuggestionDraft>
     */
    public function detect(Sport $sport, int $durationSeconds, ?int $best20MinutePower, ?float $averagePace, ThresholdSet $current): array
    {
        $suggestions = [];

        if ($sport === Sport::Bike && $best20MinutePower !== null) {
            $estimate = round($best20MinutePower * self::TWENTY_MINUTE_FACTOR);

            if ($current->ftpWatts === null || $estimate > $current->ftpWatts * (1 + self::MIN_IMPROVEMENT)) {
                $suggestions[] = new ThresholdSuggestionDraft(
                    ThresholdMetric::FtpWatts,
                    $current->ftpWatts,
                    $estimate,
                    "Best 20 minutes of {$best20MinutePower} W x 0.95 suggests an FTP of {$estimate} W.",
                );
            }
        }

        if ($sport === Sport::Run && $averagePace !== null && $durationSeconds >= self::MIN_RUN_SECONDS) {
            $threshold = $current->thresholdPaceSecondsPerKm;

            if ($threshold !== null && $averagePace < $threshold * (1 - self::MIN_IMPROVEMENT)) {
                $suggestions[] = new ThresholdSuggestionDraft(
                    ThresholdMetric::ThresholdPaceSecondsPerKm,
                    $threshold,
                    round($averagePace, 1),
                    sprintf(
                        'You held %s/km for %d minutes, faster than your threshold pace of %s/km.',
                        self::pace($averagePace), intdiv($durationSeconds, 60), self::pace($threshold),
                    ),
                );
            }
        }

        return $suggestions;
    }

    private static function pace(float $secondsPerKm): string
    {
        $seconds = (int) round($secondsPerKm);

        return sprintf('%d:%02d', intdiv($seconds, 60), $seconds % 60);
    }
}
