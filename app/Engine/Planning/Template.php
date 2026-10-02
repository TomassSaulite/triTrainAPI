<?php

declare(strict_types=1);

namespace App\Engine\Planning;

use App\Engine\Structure\WorkoutStructure;
use App\Enums\PhaseType;
use App\Enums\RaceDistance;
use App\Enums\Sport;
use App\Enums\WorkoutKind;

/**
 * A workout template as the generator sees it.
 */
final readonly class Template
{
    /**
     * @param  list<PhaseType>  $phases
     * @param  bool  $isPersonal  created by the athlete rather than part of the system library
     * @param  list<RaceDistance>|null  $distances  race distances the template is written for, null for any
     */
    public function __construct(
        public ?int $id,
        public string $slug,
        public string $name,
        public Sport $sport,
        public WorkoutKind $kind,
        public array $phases,
        public int $minSeconds,
        public int $maxSeconds,
        public WorkoutStructure $structure,
        public bool $isPersonal = false,
        public ?array $distances = null,
    ) {}

    /**
     * The same template with other length limits, e.g. when the athlete asks
     * for a length the coach would not pick on its own.
     */
    public function withBounds(int $minSeconds, int $maxSeconds): self
    {
        return new self($this->id, $this->slug, $this->name, $this->sport, $this->kind, $this->phases, $minSeconds, $maxSeconds, $this->structure, $this->isPersonal, $this->distances);
    }

    /**
     * Race openers and the like: only sensible right before a race.
     */
    public function isTaperOnly(): bool
    {
        return $this->phases === [PhaseType::Taper];
    }

    public function suits(?RaceDistance $distance): bool
    {
        return $this->distances === null || $distance === null || in_array($distance, $this->distances, true);
    }

    public function isDistanceSpecific(): bool
    {
        return $this->distances !== null;
    }

    public function supports(PhaseType $phase): bool
    {
        return in_array($phase, $this->phases, true);
    }
}
