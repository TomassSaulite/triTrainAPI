<?php

declare(strict_types=1);

namespace App\Engine\Race;

use App\Enums\RaceDistance;
use App\Enums\Sport;

/**
 * The standard course of each race distance.
 */
final readonly class RaceCourse
{
    /**
     * @param  list<Leg>  $legs
     */
    public function __construct(
        public array $legs,
        /** Both transitions together, for an age-grouper. */
        public int $transitionSeconds,
    ) {}

    public static function for(RaceDistance $distance): self
    {
        $tri = static fn (int $swim, int $bike, int $run, int $transitionMinutes): self => new self(
            [new Leg(Sport::Swim, $swim), new Leg(Sport::Bike, $bike), new Leg(Sport::Run, $run)],
            $transitionMinutes * 60,
        );
        $run = static fn (int $metres): self => new self([new Leg(Sport::Run, $metres)], 0);

        return match ($distance) {
            RaceDistance::Sprint => $tri(750, 20_000, 5_000, 4),
            RaceDistance::Olympic => $tri(1_500, 40_000, 10_000, 5),
            RaceDistance::Half => $tri(1_900, 90_000, 21_097, 7),
            RaceDistance::Full => $tri(3_800, 180_000, 42_195, 10),
            RaceDistance::FiveK => $run(5_000),
            RaceDistance::TenK => $run(10_000),
            RaceDistance::HalfMarathon => $run(21_097),
            RaceDistance::Marathon => $run(42_195),
        };
    }
}
