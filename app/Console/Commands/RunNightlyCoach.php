<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\PlanStatus;
use App\Jobs\AdaptPlan;
use App\Models\Plan;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Catches what activity syncs cannot: days where nothing happened. Runs every
 * hour and adapts the plans of athletes for whom it is now the small hours,
 * so "yesterday" is over wherever they live.
 */
#[Signature('coach:nightly {--all : Adapt every active plan regardless of local time}')]
#[Description('Mark missed workouts and adapt active plans where it is night')]
class RunNightlyCoach extends Command
{
    /**
     * Local hour at which an athlete's previous day is considered finished.
     */
    public const int LOCAL_HOUR = 3;

    public function handle(): int
    {
        $count = 0;
        $now = CarbonImmutable::now();

        Plan::query()
            ->where('status', PlanStatus::Active)
            ->whereHas('race', fn ($q) => $q->whereDate('date', '>=', $now->subDay()))
            ->with('athlete')
            ->lazyById()
            ->each(function (Plan $plan) use (&$count, $now): void {
                if ($this->option('all') || $now->setTimezone($plan->athlete->timezone)->hour === self::LOCAL_HOUR) {
                    AdaptPlan::dispatch($plan);
                    $count++;
                }
            });

        $this->components->info("Queued adaptation for {$count} active plans.");

        return self::SUCCESS;
    }
}
