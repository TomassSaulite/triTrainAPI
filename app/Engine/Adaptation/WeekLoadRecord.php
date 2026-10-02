<?php

declare(strict_types=1);

namespace App\Engine\Adaptation;

use DateTimeImmutable;

final readonly class WeekLoadRecord
{
    public function __construct(
        public DateTimeImmutable $weekStart,
        public float $plannedTss,
        public float $actualTss,
    ) {}

    public function compliance(): float
    {
        return $this->plannedTss > 0 ? $this->actualTss / $this->plannedTss : 0.0;
    }
}
