<?php

declare(strict_types=1);

namespace App\Enums;

enum RevisionReason: string
{
    case Generated = 'generated';
    case Regenerated = 'regenerated';
    case Adapted = 'adapted';
    case Manual = 'manual';
}
