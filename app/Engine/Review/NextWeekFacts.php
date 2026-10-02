<?php

declare(strict_types=1);

namespace App\Engine\Review;

use App\Enums\PhaseType;

/**
 * The week after the one under review, as currently planned.
 */
final readonly class NextWeekFacts
{
    public function __construct(
        public PhaseType $phase,
        public bool $isRecovery,
        public float $plannedTss,
        public int $plannedSeconds,
        public int $keySessions,
        /** Name of a race that falls in the week, if any. */
        public ?string $race = null,
    ) {}
}
