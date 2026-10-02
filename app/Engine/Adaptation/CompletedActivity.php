<?php

declare(strict_types=1);

namespace App\Engine\Adaptation;

use App\Enums\Sport;
use DateTimeImmutable;

final readonly class CompletedActivity
{
    public function __construct(
        public int $id,
        public Sport $sport,
        public DateTimeImmutable $date,
        public int $durationSeconds,
        public ?float $tss,
    ) {}
}
