<?php

declare(strict_types=1);

namespace App\Engine\Planning;

final readonly class WeekLoad
{
    /**
     * @param  float  $projectedCtl  fitness expected at the end of the week if it is completed as planned
     */
    public function __construct(
        public float $tss,
        public float $hours,
        public bool $isRecovery,
        public float $projectedCtl,
    ) {}
}
