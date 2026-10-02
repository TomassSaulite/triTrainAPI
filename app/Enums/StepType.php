<?php

declare(strict_types=1);

namespace App\Enums;

enum StepType: string
{
    case Warmup = 'warmup';
    case Steady = 'steady';
    case Interval = 'interval';
    case Recovery = 'recovery';
    case Rest = 'rest';
    case Cooldown = 'cooldown';
    case Repeat = 'repeat';

    /**
     * Warm-ups and cool-downs keep their length when a workout is scaled.
     */
    public function isScalable(): bool
    {
        return ! in_array($this, [self::Warmup, self::Cooldown], true);
    }
}
