<?php

declare(strict_types=1);

namespace App\Enums;

enum Experience: string
{
    case Novice = 'novice';
    case Intermediate = 'intermediate';
    case Advanced = 'advanced';
}
