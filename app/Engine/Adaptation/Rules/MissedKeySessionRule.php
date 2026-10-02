<?php

declare(strict_types=1);

namespace App\Engine\Adaptation\Rules;

use App\Engine\Adaptation\AdaptationContext;
use App\Engine\Adaptation\AdaptationRule;
use App\Engine\Adaptation\ChangeType;
use App\Engine\Adaptation\InactivityStreak;
use App\Engine\Adaptation\PlanChange;
use App\Engine\Adaptation\PlannedSession;
use App\Engine\Adaptation\WeekView;
use App\Enums\WorkoutStatus;

/**
 * A missed key session moves to the next free slot this week that does not
 * create back-to-back key days. If there is none, it takes the place of the
 * week's lowest-priority session instead of being stacked on top.
 *
 * Skipped while the athlete is in a longer break (illness, travel): making up
 * sessions then is the wrong call, and the extended-miss rule handles it.
 */
final class MissedKeySessionRule implements AdaptationRule
{
    public const string NAME = 'missed_key_session';

    public function evaluate(AdaptationContext $context, array $earlier): array
    {
        if (InactivityStreak::from($context)->days >= ExtendedMissRule::REDUCE_AFTER_DAYS) {
            return [];
        }

        $changes = [];
        $alreadyRescheduled = array_filter(array_map(
            fn (PlannedSession $s) => $s->rescheduledFromId,
            [...$context->thisWeek, ...$context->nextWeek],
        ));

        foreach ($context->thisWeek as $missed) {
            $key = "reschedule:{$missed->id}";

            if ($missed->status !== WorkoutStatus::Missed
                || ! $missed->isKey
                || $missed->date >= $context->today
                || in_array($missed->id, $alreadyRescheduled, true)
                || $context->wasApplied($key)) {
                continue;
            }

            array_push($changes, ...$this->reschedule($missed, $key, $context, [...$earlier, ...$changes]));
        }

        return $changes;
    }

    /**
     * @param  list<PlanChange>  $changesSoFar
     * @return list<PlanChange>
     */
    private function reschedule(PlannedSession $missed, string $key, AdaptationContext $context, array $changesSoFar): array
    {
        $view = new WeekView($context, $changesSoFar);
        $sunday = $context->weekStart()->modify('+6 days');
        $label = self::label($missed);

        for ($day = $context->today; $day <= $sunday; $day = $day->modify('+1 day')) {
            if ($view->canPlace($missed, $day)) {
                return [new PlanChange(
                    ChangeType::Reschedule, self::NAME, $key,
                    "Missed {$label} on {$missed->date->format('l')}; moved to {$day->format('l')}.",
                    $missed->id, $day,
                )];
            }
        }

        $victim = $this->lowestPriorityReplaceable($missed, $context, $view);

        if ($victim === null) {
            return [];
        }

        return [
            new PlanChange(
                ChangeType::Drop, self::NAME, $key,
                'Dropped '.self::label($victim)." on {$victim->date->format('l')} to make room for the missed {$label}.",
                $victim->id,
            ),
            new PlanChange(
                ChangeType::Reschedule, self::NAME, $key,
                "Missed {$label} on {$missed->date->format('l')}; moved to {$victim->date->format('l')} in place of an easier session.",
                $missed->id, $victim->date,
            ),
        ];
    }

    private function lowestPriorityReplaceable(PlannedSession $missed, AdaptationContext $context, WeekView $view): ?PlannedSession
    {
        $candidates = array_filter($context->thisWeek, fn (PlannedSession $s) => $s->isOpen()
            && ! $s->isKey
            && $s->date >= $context->today
            && $view->canPlace($missed, $s->date, ignoring: $s));

        usort($candidates, fn (PlannedSession $a, PlannedSession $b) => [$a->kind->priority(), $a->tss, $a->id] <=> [$b->kind->priority(), $b->tss, $b->id]);

        return $candidates[0] ?? null;
    }

    public static function label(PlannedSession $session): string
    {
        return str_replace('_', ' ', $session->kind->value).' '.$session->sport->value;
    }
}
