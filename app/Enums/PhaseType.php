<?php

declare(strict_types=1);

namespace App\Enums;

enum PhaseType: string
{
    case Base = 'base';
    case Build = 'build';
    case Peak = 'peak';
    case Taper = 'taper';

    /**
     * Typical TSS per hour of training in this phase, used to convert between
     * load targets and time budgets.
     */
    public function tssPerHour(): float
    {
        return match ($this) {
            self::Base => 50.0,
            self::Build => 56.0,
            self::Peak => 60.0,
            self::Taper => 52.0,
        };
    }
}
