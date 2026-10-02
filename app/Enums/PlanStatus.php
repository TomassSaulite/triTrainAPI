<?php

declare(strict_types=1);

namespace App\Enums;

enum PlanStatus: string
{
    case Active = 'active';
    case Completed = 'completed';
    case Archived = 'archived';
}
