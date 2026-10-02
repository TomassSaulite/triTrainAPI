<?php

declare(strict_types=1);

namespace App\Engine\Planning;

use App\Enums\PhaseType;
use DateTimeImmutable;

final readonly class PhaseDraft
{
    public function __construct(
        public PhaseType $type,
        public DateTimeImmutable $startDate,
        public DateTimeImmutable $endDate,
    ) {}
}
