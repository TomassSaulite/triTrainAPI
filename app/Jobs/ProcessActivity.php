<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Activity;
use App\Services\ActivityPipeline;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessActivity implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /**
     * @param  DateTimeInterface|null  $rebuildFrom  rebuild load from an earlier day, e.g. when an activity was moved later
     */
    public function __construct(
        public readonly Activity $activity,
        public readonly ?DateTimeInterface $rebuildFrom = null,
    ) {}

    public function handle(ActivityPipeline $pipeline): void
    {
        $pipeline->process($this->activity, $this->rebuildFrom);
    }
}
