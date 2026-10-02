<?php

declare(strict_types=1);

namespace App\Engine\Race;

use App\Enums\Sport;

/**
 * One leg of a race course.
 */
final readonly class Leg
{
    public function __construct(
        public Sport $sport,
        public int $distanceM,
    ) {}
}
