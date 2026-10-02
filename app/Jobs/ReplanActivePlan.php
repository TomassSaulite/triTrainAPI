<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\RevisionReason;
use App\Exceptions\PlanningException;
use App\Models\Athlete;
use App\Services\Planning\PlanService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Re-plans the athlete's active plan after one of its inputs changed. Unique
 * per athlete, so a burst of edits (several availability days, a settings
 * screen) results in one re-plan.
 */
class ReplanActivePlan implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $uniqueFor = 120;

    public function __construct(
        public readonly int $athleteId,
        public readonly string $reason,
    ) {}

    public function uniqueId(): string
    {
        return "replan-athlete-{$this->athleteId}";
    }

    public function handle(PlanService $plans): void
    {
        $plan = Athlete::find($this->athleteId)?->activePlan()->first();

        if ($plan === null) {
            return;
        }

        try {
            $plans->regenerate($plan, RevisionReason::Regenerated, $this->reason);
        } catch (PlanningException) {
            // Too close to the race to re-plan; the current plan stands.
        }
    }
}
