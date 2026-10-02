<?php

declare(strict_types=1);

namespace App\Engine\Race;

use App\Enums\Sport;

/**
 * How to race one leg: the pacing target, the time it should take, and advice.
 */
final readonly class LegPlan
{
    public function __construct(
        public Sport $sport,
        public int $distanceM,
        /** Null when the athlete has no threshold for this sport. */
        public ?PacingTarget $target,
        public ?int $predictedSeconds,
        public string $advice,
    ) {}
}
