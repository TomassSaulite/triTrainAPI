<?php

declare(strict_types=1);

namespace App\Engine\Planning\Schedule;

use App\Enums\Sport;
use App\Enums\WorkoutKind;

/**
 * A session the week needs, before it has a day or a concrete workout.
 */
final readonly class SessionSlot
{
    /**
     * @param  int|null  $brickRunSeconds  when set, the slot is a brick: this ride plus a run straight after
     * @param  string|null  $preferredSlug  template to use when the library has it
     */
    public function __construct(
        public SlotRole $role,
        public Sport $sport,
        public WorkoutKind $kind,
        public bool $isKey,
        public int $targetSeconds,
        public ?int $brickRunSeconds = null,
        public ?string $preferredSlug = null,
    ) {}

    public function isBrick(): bool
    {
        return $this->brickRunSeconds !== null;
    }

    public function totalSeconds(): int
    {
        return $this->targetSeconds + ($this->brickRunSeconds ?? 0);
    }

    /**
     * Key sessions on land (bike, run, brick) count for the "no back-to-back
     * key days" rule; swimming is low impact and exempt.
     */
    public function loadsLegs(): bool
    {
        return $this->isKey && $this->sport !== Sport::Swim;
    }

    public function downgraded(): self
    {
        return new self(SlotRole::Easy, $this->sport, WorkoutKind::Endurance, false, $this->targetSeconds);
    }
}
