<?php

declare(strict_types=1);

namespace App\Engine\Load;

use DateTimeImmutable;

final readonly class DailyLoadPoint
{
    /**
     * @param  float  $tsb  form going into the day: yesterday's CTL minus yesterday's ATL
     */
    public function __construct(
        public DateTimeImmutable $date,
        public float $tss,
        public float $ctl,
        public float $atl,
        public float $tsb,
    ) {}

    public function state(): LoadState
    {
        return new LoadState($this->ctl, $this->atl);
    }
}
