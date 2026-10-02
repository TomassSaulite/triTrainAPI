<?php

declare(strict_types=1);

namespace App\Engine\Load;

use App\Enums\Sport;

/**
 * The measurements of a completed session that stress scoring can use.
 *
 * Pace is seconds per km for runs and seconds per 100 m for swims. For runs it
 * should be normalized (grade-adjusted) pace when the source provides it.
 */
final readonly class ActivityMetrics
{
    public function __construct(
        public Sport $sport,
        public int $durationSeconds,
        public ?int $normalizedPowerWatts = null,
        public ?float $pace = null,
        public ?int $averageHeartRate = null,
    ) {}

    public function hours(): float
    {
        return $this->durationSeconds / 3600;
    }
}
