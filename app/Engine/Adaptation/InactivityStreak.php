<?php

declare(strict_types=1);

namespace App\Engine\Adaptation;

use DateTimeImmutable;

/**
 * A run of days with nothing done (illness, travel): it starts at the first
 * missed session after the last activity and runs to yesterday. Days with
 * nothing planned do not break it.
 */
final readonly class InactivityStreak
{
    public function __construct(
        public int $days,
        public ?DateTimeImmutable $since,
    ) {}

    public static function from(AdaptationContext $context): self
    {
        $since = null;

        foreach (array_reverse($context->recentDays) as $day) {
            if ($day->activities > 0) {
                break;
            }

            if ($day->missedSessions > 0) {
                $since = $day->date;
            }
        }

        if ($since === null) {
            return new self(0, null);
        }

        $yesterday = $context->today->modify('-1 day');

        return new self((int) $since->diff($yesterday)->days + 1, $since);
    }
}
