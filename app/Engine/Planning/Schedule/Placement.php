<?php

declare(strict_types=1);

namespace App\Engine\Planning\Schedule;

use DateTimeImmutable;

final readonly class Placement
{
    /**
     * @param  int|null  $maxSeconds  time left on that day for this session, null when unlimited
     */
    public function __construct(
        public SessionSlot $slot,
        public DateTimeImmutable $date,
        public ?int $maxSeconds,
    ) {}
}
