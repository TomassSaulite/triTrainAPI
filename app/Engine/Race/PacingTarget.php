<?php

declare(strict_types=1);

namespace App\Engine\Race;

/**
 * What to hold on a leg, in the athlete's own numbers.
 */
final readonly class PacingTarget
{
    public function __construct(
        /** 'watts', 's_per_km' or 's_per_100m'. */
        public string $unit,
        /** The easier end of the range (fewer watts, slower pace). */
        public float $easy,
        /** The harder end of the range. */
        public float $hard,
        /** The value to aim for. */
        public float $target,
        /** Target as a share of threshold (power or speed). */
        public float $intensity,
    ) {}
}
