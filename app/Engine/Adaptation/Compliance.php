<?php

declare(strict_types=1);

namespace App\Engine\Adaptation;

use App\Enums\WorkoutStatus;

final readonly class Compliance
{
    public function __construct(
        public float $ratio,
        public WorkoutStatus $status,
    ) {}
}
