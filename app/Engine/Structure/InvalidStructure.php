<?php

declare(strict_types=1);

namespace App\Engine\Structure;

use InvalidArgumentException;

final class InvalidStructure extends InvalidArgumentException
{
    public static function at(string $path, string $problem): self
    {
        return new self("{$path}: {$problem}");
    }
}
