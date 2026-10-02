<?php

declare(strict_types=1);

namespace App\Engine\Planning\Schedule;

final readonly class WeekSchedule
{
    /**
     * @param  list<Placement>  $placements  in calendar order
     * @param  list<SessionSlot>  $unplaced  sessions that did not fit anywhere
     */
    public function __construct(
        public array $placements,
        public array $unplaced,
    ) {}
}
