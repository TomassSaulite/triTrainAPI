<?php

declare(strict_types=1);

namespace App\Engine\Review;

use App\Enums\PhaseType;
use App\Enums\Sport;

/**
 * What was planned and what was done in the week under review.
 */
final readonly class WeekFacts
{
    /**
     * @param  list<string>  $missedKeySessions  titles of key sessions not done
     * @param  array<value-of<Sport>, array{planned_s: int, actual_s: int}>  $sportTime
     */
    public function __construct(
        public PhaseType $phase,
        public bool $isRecovery,
        public float $plannedTss,
        public float $actualTss,
        public int $plannedSeconds,
        public int $actualSeconds,
        public int $keySessions,
        public array $missedKeySessions,
        public array $sportTime = [],
        public ?float $ctlBefore = null,
        public ?float $ctlAfter = null,
        public ?float $tsbAfter = null,
        public ?FeelSummary $feel = null,
    ) {}

    public function compliance(): ?float
    {
        return $this->plannedTss > 0 ? $this->actualTss / $this->plannedTss : null;
    }
}
