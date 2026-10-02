<?php

declare(strict_types=1);

namespace App\Engine\Planning\Schedule;

use App\Enums\Sport;
use DateTimeImmutable;

/**
 * One calendar day of a week being scheduled. Mutable working state that
 * never leaves the scheduler.
 *
 * @internal
 */
final class Day
{
    public const int MAX_SESSIONS = 2;

    /**
     * @var list<SessionSlot>
     */
    public array $slots = [];

    /**
     * Only recovery, technique or swim sessions: the day after the long ride
     * or after a race.
     */
    public bool $easyOnly = false;

    /**
     * Stricter than easyOnly: no key session of any sport, and only recovery,
     * technique or endurance work. The first days back after illness or injury.
     */
    public bool $keyFree = false;

    public function __construct(
        public readonly int $weekday,
        public readonly DateTimeImmutable $date,
        public readonly bool $available,
        public readonly bool $isPoolDay,
        public readonly ?int $capacitySeconds,
    ) {}

    public function hasLegKey(): bool
    {
        foreach ($this->slots as $slot) {
            if ($slot->loadsLegs()) {
                return true;
            }
        }

        return false;
    }

    public function hasSport(Sport $sport): bool
    {
        foreach ($this->slots as $slot) {
            if ($slot->sport === $sport || ($slot->isBrick() && $sport === Sport::Run)) {
                return true;
            }
        }

        return false;
    }

    public function usedSeconds(): int
    {
        return array_sum(array_map(fn (SessionSlot $s) => $s->totalSeconds(), $this->slots));
    }

    public function remainingSeconds(): ?int
    {
        return $this->capacitySeconds === null ? null : max(0, $this->capacitySeconds - $this->usedSeconds());
    }

    public function isFull(): bool
    {
        return count($this->slots) >= self::MAX_SESSIONS;
    }
}
