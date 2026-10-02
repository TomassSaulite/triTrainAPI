<?php

declare(strict_types=1);

namespace App\Enums;

enum ThresholdSource: string
{
    case Test = 'test';
    case Estimated = 'estimated';
    case AutoDetected = 'auto_detected';
}
