<?php

declare(strict_types=1);

namespace App\Engine\Adaptation\Rules;

use App\Engine\Adaptation\AdaptationContext;
use App\Engine\Adaptation\AdaptationRule;
use App\Engine\Adaptation\ChangeType;
use App\Engine\Adaptation\PlanChange;
use App\Engine\Adaptation\PlannedSession;

/**
 * Form (TSB) below about -30 for three days or more: the next key session
 * becomes a recovery session.
 */
final class FatigueRule implements AdaptationRule
{
    public const string NAME = 'fatigue';

    public const float TSB_LIMIT = -30.0;

    public const int DAYS = 3;

    public function evaluate(AdaptationContext $context, array $earlier): array
    {
        $recent = array_slice($context->recentTsb, -self::DAYS);
        $key = 'fatigue:'.$context->today->format('Y-m-d');

        if (count($recent) < self::DAYS || max($recent) >= self::TSB_LIMIT || $context->wasApplied($key)) {
            return [];
        }

        $next = $this->nextKeySession($context, $earlier);

        if ($next === null) {
            return [];
        }

        return [new PlanChange(
            ChangeType::Recover, self::NAME, $key,
            sprintf(
                'Form has been below TSB %d for %d days (now %d): %s on %s swapped for recovery.',
                self::TSB_LIMIT, self::DAYS, round(end($recent)), MissedKeySessionRule::label($next), $next->date->format('l'),
            ),
            $next->id,
        )];
    }

    /**
     * @param  list<PlanChange>  $earlier
     */
    private function nextKeySession(AdaptationContext $context, array $earlier): ?PlannedSession
    {
        $touched = array_map(fn (PlanChange $c) => $c->sessionId, $earlier);
        $upcoming = array_filter(
            [...$context->thisWeek, ...$context->nextWeek],
            fn (PlannedSession $s) => $s->isOpen() && $s->isKey && $s->date >= $context->today && ! in_array($s->id, $touched, true),
        );

        usort($upcoming, fn (PlannedSession $a, PlannedSession $b) => [$a->date, $a->id] <=> [$b->date, $b->id]);

        return $upcoming[0] ?? null;
    }
}
