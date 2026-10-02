<?php

declare(strict_types=1);

namespace App\Engine\Race;

/**
 * Speed on a flat road at a steady power, from the standard power balance:
 * P · efficiency = (½ ρ CdA v² + Crr m g) · v. Rough by design: it assumes no
 * wind or hills, and typical age-grouper position and equipment.
 */
final class BikeSpeedModel
{
    public const float AIR_DENSITY = 1.2;

    /** Drag area of an age-grouper on aero bars, allowing for real roads. */
    public const float CDA = 0.33;

    public const float ROLLING_RESISTANCE = 0.004;

    public const float DRIVETRAIN_EFFICIENCY = 0.975;

    public const float BIKE_KG = 9.0;

    private const float GRAVITY = 9.81;

    /**
     * @return float metres per second
     */
    public static function speed(float $watts, float $riderKg): float
    {
        $power = $watts * self::DRIVETRAIN_EFFICIENCY;
        $drag = 0.5 * self::AIR_DENSITY * self::CDA;
        $rolling = self::ROLLING_RESISTANCE * ($riderKg + self::BIKE_KG) * self::GRAVITY;

        // Power needed grows monotonically with speed, so bisection always converges.
        [$low, $high] = [0.0, 30.0];
        for ($i = 0; $i < 60; $i++) {
            $v = ($low + $high) / 2;
            ($drag * $v ** 3 + $rolling * $v) < $power ? $low = $v : $high = $v;
        }

        return ($low + $high) / 2;
    }
}
