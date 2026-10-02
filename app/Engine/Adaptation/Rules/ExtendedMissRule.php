<?php

declare(strict_types=1);

namespace App\Engine\Adaptation\Rules;

use App\Engine\Adaptation\AdaptationContext;
use App\Engine\Adaptation\AdaptationRule;
use App\Engine\Adaptation\ChangeType;
use App\Engine\Adaptation\InactivityStreak;
use App\Engine\Adaptation\PlanChange;

/**
 * Three or more days missed (illness, travel): the rest of the week drops to
 * about 70% load. Seven or more: the plan is regenerated from today, which
 * also re-checks whether the fitness target is still reachable.
 */
final class ExtendedMissRule implements AdaptationRule
{
    public const string NAME = 'extended_miss';

    public const int REDUCE_AFTER_DAYS = 3;

    public const int REGENERATE_AFTER_DAYS = 7;

    public const float REDUCED_LOAD = 0.7;

    public function evaluate(AdaptationContext $context, array $earlier): array
    {
        $streak = InactivityStreak::from($context);

        if ($streak->days >= self::REGENERATE_AFTER_DAYS) {
            $key = 'regenerate:'.$streak->since->format('Y-m-d');

            return $context->wasApplied($key) ? [] : [new PlanChange(
                ChangeType::Regenerate, self::NAME, $key,
                "Nothing done for {$streak->days} days: re-planned from today and re-checked the fitness target.",
            )];
        }

        $key = 'reduce:'.$context->weekStart()->format('Y-m-d');

        if ($streak->days < self::REDUCE_AFTER_DAYS || $context->wasApplied($key)) {
            return [];
        }

        $changes = [];

        foreach ($context->thisWeek as $session) {
            if ($session->isOpen() && $session->date >= $context->today) {
                $changes[] = new PlanChange(
                    ChangeType::Scale, self::NAME, $key,
                    "{$streak->days} days missed: the rest of the week is eased to ".(int) (self::REDUCED_LOAD * 100).'% load.',
                    $session->id, factor: self::REDUCED_LOAD,
                );
            }
        }

        return $changes;
    }
}
