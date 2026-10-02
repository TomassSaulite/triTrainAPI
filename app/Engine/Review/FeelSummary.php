<?php

declare(strict_types=1);

namespace App\Engine\Review;

/**
 * How a week's sessions felt, summed up from the athlete's ratings. Averages
 * are null when nothing was rated on that scale.
 */
final readonly class FeelSummary
{
    /**
     * @param  list<string>  $painAreas  where pain was reported ('' when no area was given)
     */
    public function __construct(
        public int $sessions,
        public int $rated,
        public ?float $rpe = null,
        public ?float $muscles = null,
        public ?float $breathing = null,
        public ?float $energy = null,
        public ?float $mood = null,
        public array $painAreas = [],
        /** Easy sessions (endurance, recovery, technique) rated RPE 6 or more. */
        public int $easyFeltHard = 0,
    ) {}
}
