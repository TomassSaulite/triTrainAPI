<?php

declare(strict_types=1);

namespace App\Engine\Load;

/**
 * Fitness (CTL) and fatigue (ATL) at the end of a day. Form (TSB) is derived.
 */
final readonly class LoadState
{
    public function __construct(
        public float $ctl = 0.0,
        public float $atl = 0.0,
    ) {}

    public function tsb(): float
    {
        return $this->ctl - $this->atl;
    }
}
