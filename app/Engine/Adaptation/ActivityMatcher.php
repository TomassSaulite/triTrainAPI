<?php

declare(strict_types=1);

namespace App\Engine\Adaptation;

/**
 * Pairs a completed activity with the planned session it fulfils: same sport,
 * same day give or take one, closest duration wins (same day breaks ties).
 * Sessions already marked missed can still be claimed by an activity that
 * synced late. Unmatched activities still count toward load.
 */
final class ActivityMatcher
{
    public const int MAX_DAY_OFFSET = 1;

    /**
     * @param  list<PlannedSession>  $candidates
     */
    public function match(CompletedActivity $activity, array $candidates): ?PlannedSession
    {
        $best = null;
        $bestRank = null;

        foreach ($candidates as $session) {
            if (! $session->isMatchable() || $session->sport !== $activity->sport) {
                continue;
            }

            $dayOffset = abs((int) $session->date->setTime(0, 0)->diff($activity->date->setTime(0, 0))->format('%r%a'));

            if ($dayOffset > self::MAX_DAY_OFFSET) {
                continue;
            }

            $rank = [abs($session->durationSeconds - $activity->durationSeconds), $dayOffset, $session->isKey ? 0 : 1, $session->id];

            if ($bestRank === null || $rank < $bestRank) {
                [$best, $bestRank] = [$session, $rank];
            }
        }

        return $best;
    }
}
