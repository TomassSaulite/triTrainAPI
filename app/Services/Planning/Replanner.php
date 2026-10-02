<?php

declare(strict_types=1);

namespace App\Services\Planning;

use App\Jobs\ReplanActivePlan;
use App\Models\Athlete;
use DateTimeInterface;

/**
 * Decides whether a change to an athlete's inputs affects their active plan
 * and, if so, queues a re-plan.
 */
class Replanner
{
    /**
     * @param  DateTimeInterface|null  $affectedDate  the day the change is about; changes outside the plan's future are ignored
     */
    public function inputsChanged(Athlete $athlete, string $reason, ?DateTimeInterface $affectedDate = null): bool
    {
        $plan = $athlete->activePlan()->with('race')->first();

        if ($plan === null) {
            return false;
        }

        if ($affectedDate !== null) {
            $date = $affectedDate->format('Y-m-d');

            if ($date < $athlete->today()->toDateString() || $date > $plan->race->date->toDateString()) {
                return false;
            }
        }

        ReplanActivePlan::dispatch($athlete->id, $reason);

        return true;
    }
}
