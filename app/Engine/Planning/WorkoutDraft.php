<?php

declare(strict_types=1);

namespace App\Engine\Planning;

use App\Engine\Structure\WorkoutStructure;
use App\Enums\Sport;
use App\Enums\WorkoutKind;
use DateTimeImmutable;

/**
 * A planned session. A brick is a parent draft (sport = brick, no structure)
 * whose bike and run halves are its children, so each half can still be
 * matched to its own activity.
 */
final readonly class WorkoutDraft
{
    /**
     * @param  list<WorkoutDraft>  $children
     */
    public function __construct(
        public DateTimeImmutable $date,
        public Sport $sport,
        public WorkoutKind $kind,
        public bool $isKey,
        public string $title,
        public int $durationSeconds,
        public ?int $distanceMeters,
        public float $tss,
        public ?WorkoutStructure $structure,
        public ?int $templateId = null,
        public array $children = [],
    ) {}
}
