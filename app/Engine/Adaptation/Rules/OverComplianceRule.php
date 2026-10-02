<?php

declare(strict_types=1);

namespace App\Engine\Adaptation\Rules;

use App\Engine\Adaptation\AdaptationContext;
use App\Engine\Adaptation\AdaptationRule;
use App\Engine\Adaptation\ChangeType;
use App\Engine\Adaptation\PlanChange;
use App\Engine\Adaptation\PlannedSession;
use App\Engine\Adaptation\WeekLoadRecord;
use App\Engine\Load\LoadModel;

/**
 * Two weeks in a row above ~110% of plan: next week's load goes up 5%, but
 * never past what the athlete's ramp cap allows.
 */
final class OverComplianceRule implements AdaptationRule
{
    public const string NAME = 'over_compliance';

    public const float COMPLIANCE_ABOVE = 1.10;

    public const int WEEKS = 2;

    public const float RAISE = 1.05;

    public function __construct(
        private readonly LoadModel $model = new LoadModel,
    ) {}

    public function evaluate(AdaptationContext $context, array $earlier): array
    {
        $weeks = array_slice($context->completedWeeks, -self::WEEKS);
        $nextMonday = $context->weekStart()->modify('+7 days');
        $key = 'raise:'.$nextMonday->format('Y-m-d');

        if (count($weeks) < self::WEEKS
            || array_filter($weeks, fn (WeekLoadRecord $w) => $w->compliance() <= self::COMPLIANCE_ABOVE) !== []
            || $context->wasApplied($key)) {
            return [];
        }

        $sessions = array_filter($context->nextWeek, fn (PlannedSession $s) => $s->isOpen());
        $planned = array_sum(array_map(fn (PlannedSession $s) => $s->tss, $sessions));

        if ($planned <= 0) {
            return [];
        }

        $rampCeiling = 7 * $this->model->dailyTssToReach($context->currentCtl, $context->currentCtl + $context->preferences->maxRampRate, 7);
        $factor = round(min(self::RAISE, $rampCeiling / $planned), 3);

        if ($factor <= 1.005) {
            return [];
        }

        $reason = sprintf(
            'Last %d weeks came in above %d%% of plan: next week is raised by %d%%%s.',
            self::WEEKS, self::COMPLIANCE_ABOVE * 100, round(($factor - 1) * 100), $factor < self::RAISE ? ' (held back by your ramp limit)' : '',
        );

        return array_values(array_map(
            fn (PlannedSession $s) => new PlanChange(ChangeType::Scale, self::NAME, $key, $reason, $s->id, factor: $factor),
            $sessions,
        ));
    }
}
