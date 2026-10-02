<?php

declare(strict_types=1);

namespace App\Engine\Review;

/**
 * The coach's summary of a week: a verdict, a headline, supporting notes,
 * and what the following week brings.
 */
final readonly class WeeklyReview
{
    /**
     * @param  list<string>  $notes
     */
    public function __construct(
        public Verdict $verdict,
        public string $headline,
        public array $notes,
        public ?string $nextWeek,
    ) {}
}
