<?php

declare(strict_types=1);

namespace App\Engine\Planning;

use App\Engine\Structure\WorkoutAnalysis;
use App\Engine\Structure\WorkoutStructure;

final readonly class SizedWorkout
{
    public function __construct(
        public WorkoutStructure $structure,
        public WorkoutAnalysis $analysis,
    ) {}
}
