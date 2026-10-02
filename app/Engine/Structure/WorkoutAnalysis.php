<?php

declare(strict_types=1);

namespace App\Engine\Structure;

final readonly class WorkoutAnalysis
{
    public function __construct(
        public int $durationSeconds,
        public ?int $distanceMeters,
        public float $tss,
        public float $intensityFactor,
    ) {}
}
