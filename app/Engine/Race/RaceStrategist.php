<?php

declare(strict_types=1);

namespace App\Engine\Race;

use App\Engine\ThresholdSet;
use App\Enums\Experience;
use App\Enums\RaceDistance;
use App\Enums\Sport;

/**
 * Builds the race-day plan for a race: how hard to go on each leg, how long it
 * should take, and how to fuel. Pure: thresholds, experience and weight in.
 */
class RaceStrategist
{
    /**
     * Sustainable intensity per distance and sport as [easy, hard] shares of
     * threshold: CSS speed for the swim, FTP for the bike, threshold speed for
     * the run. Running races are faster than a run off the bike.
     */
    private const array INTENSITY = [
        'sprint' => ['swim' => [0.97, 1.02], 'bike' => [0.88, 0.95], 'run' => [0.95, 1.0]],
        'olympic' => ['swim' => [0.95, 1.0], 'bike' => [0.82, 0.88], 'run' => [0.92, 0.97]],
        'half' => ['swim' => [0.92, 0.97], 'bike' => [0.72, 0.79], 'run' => [0.85, 0.9]],
        'full' => ['swim' => [0.88, 0.94], 'bike' => [0.65, 0.72], 'run' => [0.76, 0.82]],
        '5k' => ['run' => [1.02, 1.06]],
        '10k' => ['run' => [0.97, 1.01]],
        'half_marathon' => ['run' => [0.92, 0.96]],
        'marathon' => ['run' => [0.85, 0.89]],
    ];

    /** Where in the range each experience level aims. */
    private const array EXPERIENCE_POSITION = ['novice' => 0.25, 'intermediate' => 0.5, 'advanced' => 0.75];

    /** Open water is slower than the pool: sighting, no walls to push off. */
    public const float OPEN_WATER_FACTOR = 1.03;

    /** Climbs may go this much above the bike target, never more. */
    public const float CLIMB_ALLOWANCE = 1.1;

    /** Body weight assumed for bike speed when the athlete has not given one. */
    public const float DEFAULT_WEIGHT_KG = 75.0;

    /** Typical finish times, used for fueling advice when splits cannot be predicted. */
    private const array TYPICAL_HOURS = [
        'sprint' => 1.25, 'olympic' => 2.5, 'half' => 5.5, 'full' => 12.0,
        '5k' => 0.4, '10k' => 0.9, 'half_marathon' => 2.0, 'marathon' => 4.25,
    ];

    public function plan(RaceDistance $distance, ThresholdSet $thresholds, Experience $experience, ?float $weightKg): RaceStrategy
    {
        $course = RaceCourse::for($distance);
        $position = self::EXPERIENCE_POSITION[$experience->value];
        $legs = [];
        $missing = [];

        foreach ($course->legs as $leg) {
            [$easy, $hard] = self::INTENSITY[$distance->value][$leg->sport->value];
            $target = $this->target($leg->sport, $thresholds, $easy, $hard, $easy + ($hard - $easy) * $position);

            if ($target === null) {
                $missing[] = match ($leg->sport) {
                    Sport::Swim => 'Add your CSS (swim threshold pace) for a swim pace and split.',
                    Sport::Bike => 'Add your FTP for a power target and bike split.',
                    default => 'Add your run threshold pace for a run pace and split.',
                };
            }

            $legs[] = new LegPlan(
                $leg->sport,
                $leg->distanceM,
                $target,
                $target === null ? null : $this->duration($leg, $target, $distance, $weightKg),
                $this->advice($leg->sport, $distance, $target),
            );
        }

        if ($weightKg === null && in_array(Sport::Bike, array_map(fn (Leg $l) => $l->sport, $course->legs), true)) {
            $missing[] = 'Add your weight for a more accurate bike split and fueling in grams.';
        }

        $finish = in_array(null, array_map(fn (LegPlan $l) => $l->predictedSeconds, $legs), true)
            ? null
            : array_sum(array_map(fn (LegPlan $l) => (int) $l->predictedSeconds, $legs)) + $course->transitionSeconds;
        $hours = ($finish ?? self::TYPICAL_HOURS[$distance->value] * 3600) / 3600;

        return new RaceStrategy(
            $legs,
            $course->transitionSeconds,
            $finish,
            $this->fuelingBefore($distance, $hours, $weightKg),
            $this->fuelingDuring($distance, $hours, $legs, $experience),
            $missing,
        );
    }

    private function target(Sport $sport, ThresholdSet $thresholds, float $easy, float $hard, float $aim): ?PacingTarget
    {
        $threshold = match ($sport) {
            Sport::Swim => $thresholds->cssSecondsPer100m,
            Sport::Bike => $thresholds->ftpWatts,
            default => $thresholds->thresholdPaceSecondsPerKm,
        };

        if ($threshold === null) {
            return null;
        }

        return $sport === Sport::Bike
            // Power: a share of FTP.
            ? new PacingTarget('watts', round($threshold * $easy), round($threshold * $hard), round($threshold * $aim), $aim)
            // Pace: threshold pace divided by the share of threshold speed.
            : new PacingTarget(
                $sport === Sport::Swim ? 's_per_100m' : 's_per_km',
                round($threshold / $easy),
                round($threshold / $hard),
                round($threshold / $aim),
                $aim,
            );
    }

    private function duration(Leg $leg, PacingTarget $target, RaceDistance $distance, ?float $weightKg): int
    {
        return (int) round(match ($leg->sport) {
            Sport::Swim => $leg->distanceM / 100 * $target->target * self::OPEN_WATER_FACTOR,
            Sport::Bike => $leg->distanceM / BikeSpeedModel::speed($target->target, $weightKg ?? self::DEFAULT_WEIGHT_KG),
            default => $leg->distanceM / 1000 * $target->target,
        });
    }

    private function advice(Sport $sport, RaceDistance $distance, ?PacingTarget $target): string
    {
        $long = in_array($distance, [RaceDistance::Half, RaceDistance::Full], true);

        return match (true) {
            $sport === Sport::Swim => 'Start controlled for the first 200 m, then settle into your pace. Sight every 6 to 8 strokes and find feet to draft.',
            $sport === Sport::Bike && $long => sprintf(
                'Hold back for the first 20 minutes and keep the power steady%s. Eat and drink from the start. The race is decided on the run.',
                $target === null ? '' : sprintf('; on climbs stay under %d W', round($target->target * self::CLIMB_ALLOWANCE)),
            ),
            $sport === Sport::Bike => 'Ride hard but steady. Ease off in the last 2 km and spin your legs ready for the run.',
            ! $distance->isTriathlon() && in_array($distance, [RaceDistance::FiveK, RaceDistance::TenK], true) => 'Run even splits. The first kilometre is the one people get wrong: it should feel easy.',
            ! $distance->isTriathlon() => sprintf(
                'Start at the easy end of the range for the first 5 km, then settle at your target. Push only in the last %d km.',
                $distance === RaceDistance::Marathon ? 10 : 5,
            ),
            $long => 'The first kilometres off the bike feel easy: stay at your pace, not faster. Walk the aid stations if it helps you keep fuelling.',
            default => 'Your legs will feel heavy for the first kilometre. Settle into your pace and finish strong.',
        };
    }

    /**
     * @return list<string>
     */
    private function fuelingBefore(RaceDistance $distance, float $hours, ?float $weightKg): array
    {
        $grams = static fn (float $low, float $high): string => $weightKg === null
            ? sprintf('%s to %s g per kg of body weight', self::number($low), self::number($high))
            : sprintf('about %d to %d g', round($low * $weightKg, -1), round($high * $weightKg, -1));
        $notes = [];

        if ($hours >= 2.5) {
            $notes[] = sprintf('For the two days before the race, carb-load: %s of carbohydrate a day, from easy-to-digest food.', $grams(8, 10));
        } elseif ($hours >= 1.5) {
            $notes[] = 'Eat carb-rich meals the day before. A full carb-load is not needed for this distance.';
        }

        $notes[] = $hours >= 1.5
            ? sprintf('Breakfast 2 to 3 hours before the start: %s of carbohydrate, low in fibre and fat, with 500 ml of fluid.', $grams(1, 2))
            : 'A light carb-based breakfast about 2 hours before the start, and a few sips of water.';

        if ($distance->isTriathlon()) {
            $notes[] = 'Nothing new on race day: use only food and drinks you have tried in training.';
        }

        return $notes;
    }

    /**
     * @param  list<LegPlan>  $legs
     * @return list<string>
     */
    private function fuelingDuring(RaceDistance $distance, float $hours, array $legs, Experience $experience): array
    {
        if (! $distance->isTriathlon()) {
            return match (true) {
                $hours < 1.25 => ['Nothing needed beyond water at the aid stations.'],
                $hours < 2.5 => ['Water at the aid stations, and a gel around halfway if you like.'],
                default => ['30 to 60 g of carbohydrate per hour: a gel every 30 to 40 minutes from 30 minutes in, each with water.'],
            };
        }

        if ($hours < 1.25) {
            return ['On the bike, water as you need it; a gel is optional. On the run, water only.'];
        }

        [$low, $high] = match (true) {
            $hours < 2.5 => [40, 60],
            $experience === Experience::Novice => [60, 75],
            default => [75, 90],
        };
        $bike = collect($legs)->first(fn (LegPlan $l) => $l->sport === Sport::Bike);
        $total = $bike?->predictedSeconds === null
            ? ''
            : sprintf(', about %d g over the ride', round(($low + $high) / 2 * $bike->predictedSeconds / 3600, -1));

        return [
            sprintf('On the bike: %d to %d g of carbohydrate per hour%s, from drink mix, gels or bars. Start in the first 15 minutes.', $low, $high, $total),
            $hours >= 4
                ? 'Drink 500 to 750 ml per hour on the bike, with electrolytes (about 500 to 700 mg of sodium per hour), more in the heat.'
                : 'Drink 500 to 750 ml per hour on the bike, more in the heat.',
            $hours >= 2.5
                ? 'On the run: 30 to 60 g of carbohydrate per hour from gels, cola or sports drink at the aid stations, and drink to thirst.'
                : 'On the run: water at the aid stations, and a gel at halfway if you need it.',
        ];
    }

    private static function number(float $value): string
    {
        return rtrim(rtrim(number_format($value, 1), '0'), '.');
    }
}
