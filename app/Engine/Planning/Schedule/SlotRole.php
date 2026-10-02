<?php

declare(strict_types=1);

namespace App\Engine\Planning\Schedule;

/**
 * What a session is for in the week skeleton; decides placement order and rules.
 */
enum SlotRole: string
{
    case LongRide = 'long_ride';
    case LongRun = 'long_run';
    case Quality = 'quality';
    case Swim = 'swim';
    case Easy = 'easy';
}
