<?php

declare(strict_types=1);

namespace App\Engine\Adaptation;

use App\Enums\WorkoutStatus;

/**
 * Compliance = actual TSS / planned TSS: at least 80% is completed, 50-80% is
 * partial, below 50% (or nothing by the end of the day) is missed.
 */
final class ComplianceScorer
{
    public const float COMPLETED_AT = 0.8;

    public const float PARTIAL_AT = 0.5;

    public function score(float $actualTss, float $plannedTss): Compliance
    {
        $ratio = $plannedTss > 0 ? $actualTss / $plannedTss : 1.0;

        return new Compliance(round($ratio, 2), $this->statusFor($ratio));
    }

    /**
     * Combined result for the halves of a brick.
     *
     * @param  list<array{actual: float, planned: float}>  $parts
     */
    public function combine(array $parts): Compliance
    {
        $actual = array_sum(array_column($parts, 'actual'));
        $planned = array_sum(array_column($parts, 'planned'));

        return $this->score($actual, $planned);
    }

    public function statusFor(float $ratio): WorkoutStatus
    {
        return match (true) {
            $ratio >= self::COMPLETED_AT => WorkoutStatus::Completed,
            $ratio >= self::PARTIAL_AT => WorkoutStatus::Partial,
            default => WorkoutStatus::Missed,
        };
    }
}
