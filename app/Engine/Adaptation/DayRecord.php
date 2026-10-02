<?php

declare(strict_types=1);

namespace App\Engine\Adaptation;

use DateTimeImmutable;

/**
 * What was planned and what happened on one past day.
 */
final readonly class DayRecord
{
    public function __construct(
        public DateTimeImmutable $date,
        public int $plannedSessions,
        public int $missedSessions,
        public int $activities,
    ) {}
}
