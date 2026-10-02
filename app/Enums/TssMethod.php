<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * How an activity's training stress score was derived, best source first.
 */
enum TssMethod: string
{
    case Power = 'power';
    case Pace = 'pace';
    case HeartRate = 'heart_rate';
    case Estimated = 'estimated';
    case Provided = 'provided';
}
