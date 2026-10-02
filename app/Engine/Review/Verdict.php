<?php

declare(strict_types=1);

namespace App\Engine\Review;

/**
 * The coach's one-word read of a finished week.
 */
enum Verdict: string
{
    /** Close to the planned load with every key session done. */
    case OnTrack = 'on_track';
    /** Enough load, but one or more key sessions were missed. */
    case KeysMissed = 'keys_missed';
    /** Clearly less than planned. */
    case Under = 'under';
    /** Clearly more than planned. */
    case Over = 'over';
    /** Nothing was planned. */
    case Rest = 'rest';
}
