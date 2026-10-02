<?php

declare(strict_types=1);

namespace App\Engine\Adaptation;

use App\Enums\Sport;
use App\Enums\WorkoutKind;
use App\Enums\WorkoutStatus;
use DateTimeImmutable;

/**
 * A planned workout as the adaptation engine sees it. For bricks, the engine
 * works with the bike and run halves, which are matched individually.
 */
final readonly class PlannedSession
{
    public function __construct(
        public int $id,
        public DateTimeImmutable $date,
        public Sport $sport,
        public WorkoutKind $kind,
        public bool $isKey,
        public int $durationSeconds,
        public float $tss,
        public WorkoutStatus $status,
        public ?int $activityId = null,
        public ?int $parentId = null,
        public ?int $rescheduledFromId = null,
    ) {}

    public function isOpen(): bool
    {
        return $this->status->isOpen() && $this->activityId === null;
    }

    /**
     * Open sessions, and missed ones whose activity may simply have synced late.
     */
    public function isMatchable(): bool
    {
        return $this->activityId === null && ($this->status->isOpen() || $this->status === WorkoutStatus::Missed);
    }

    /**
     * Sessions that happened or will happen: they count for spacing and day load.
     */
    public function isActive(): bool
    {
        return $this->status !== WorkoutStatus::Dropped && $this->status !== WorkoutStatus::Missed;
    }

    /**
     * Key sessions on land count for the "no back-to-back key days" rule.
     */
    public function loadsLegs(): bool
    {
        return $this->isKey && $this->sport !== Sport::Swim;
    }
}
