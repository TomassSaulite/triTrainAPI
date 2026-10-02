<?php

declare(strict_types=1);

namespace App\Engine\Planning;

use App\Enums\RaceDistance;
use App\Enums\Sport;

/**
 * Splits weekly training time across swim, bike and run.
 */
final class SportSplitter
{
    /**
     * Extra share moved to the athlete's weakest discipline.
     */
    public const float WEAKNESS_SHIFT = 0.075;

    /**
     * @return array<value-of<Sport>, float> shares adding up to 1
     */
    public function shares(RaceDistance $distance, CoachPreferences $preferences, ?Sport $weakest = null): array
    {
        $shares = $preferences->sportShare ?? $distance->defaultSportShare();

        if ($preferences->sportShare !== null || $weakest === null || ! isset($shares[$weakest->value])) {
            return $shares;
        }

        return $this->shift($shares, $weakest, self::WEAKNESS_SHIFT);
    }

    /**
     * Moves $amount of the time to $towards, taking it from the other
     * disciplines in proportion to their shares.
     *
     * @param  array<value-of<Sport>, float>  $shares
     * @return array<value-of<Sport>, float>
     */
    public function shift(array $shares, Sport $towards, float $amount): array
    {
        if (! isset($shares[$towards->value])) {
            return $shares;
        }

        $others = array_sum($shares) - $shares[$towards->value];
        $amount = min($amount, $others * 0.8);

        foreach ($shares as $sport => $share) {
            $shares[$sport] = $sport === $towards->value
                ? $share + $amount
                : $share - ($others > 0 ? $amount * $share / $others : 0);
        }

        return $shares;
    }
}
