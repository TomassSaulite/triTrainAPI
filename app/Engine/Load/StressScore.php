<?php

declare(strict_types=1);

namespace App\Engine\Load;

use App\Enums\TssMethod;

final readonly class StressScore
{
    public function __construct(
        public float $tss,
        public ?float $intensityFactor,
        public TssMethod $method,
    ) {}
}
