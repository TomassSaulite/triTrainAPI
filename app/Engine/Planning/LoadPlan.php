<?php

declare(strict_types=1);

namespace App\Engine\Planning;

final readonly class LoadPlan
{
    /**
     * @param  list<WeekLoad>  $weeks
     * @param  list<string>  $warnings
     */
    public function __construct(
        public float $startingCtl,
        public float $targetCtl,
        public float $projectedRaceDayTsb,
        public array $weeks,
        public array $warnings,
    ) {}
}
