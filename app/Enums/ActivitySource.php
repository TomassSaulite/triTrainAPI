<?php

declare(strict_types=1);

namespace App\Enums;

enum ActivitySource: string
{
    case Strava = 'strava';
    case HealthConnect = 'health_connect';
    case Fit = 'fit';
    case Manual = 'manual';
}
