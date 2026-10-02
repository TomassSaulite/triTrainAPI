<?php

declare(strict_types=1);

namespace App\Enums;

enum WorkoutStatus: string
{
    case Planned = 'planned';
    case Moved = 'moved';
    case Completed = 'completed';
    case Partial = 'partial';
    case Missed = 'missed';
    case Dropped = 'dropped';

    /**
     * Open workouts are still in the future of the athlete's week: they can be
     * matched to an activity and changed by re-planning.
     */
    public function isOpen(): bool
    {
        return $this === self::Planned || $this === self::Moved;
    }

    /**
     * @return list<self>
     */
    public static function open(): array
    {
        return [self::Planned, self::Moved];
    }
}
