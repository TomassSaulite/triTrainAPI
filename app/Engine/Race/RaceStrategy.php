<?php

declare(strict_types=1);

namespace App\Engine\Race;

/**
 * The coach's race-day plan: pacing per leg, predicted splits and fueling.
 */
final readonly class RaceStrategy
{
    /**
     * @param  list<LegPlan>  $legs
     * @param  list<string>  $fuelingBefore
     * @param  list<string>  $fuelingDuring
     * @param  list<string>  $missing  what the athlete could add for a fuller plan
     */
    public function __construct(
        public array $legs,
        public int $transitionSeconds,
        /** Null unless every leg could be predicted. */
        public ?int $finishSeconds,
        public array $fuelingBefore,
        public array $fuelingDuring,
        public array $missing,
    ) {}
}
