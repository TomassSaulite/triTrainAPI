<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Plan;
use App\Services\Adaptation\AdaptationService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class AdaptPlan implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly Plan $plan,
    ) {}

    public function uniqueId(): string
    {
        return "adapt-plan-{$this->plan->id}";
    }

    public function handle(AdaptationService $adaptation): void
    {
        $adaptation->adapt($this->plan);
    }
}
