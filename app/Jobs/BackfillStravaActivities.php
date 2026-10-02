<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Integrations\Strava\StravaImporter;
use App\Models\StravaConnection;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class BackfillStravaActivities implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 300;

    public function __construct(
        public readonly int $connectionId,
    ) {}

    public function handle(StravaImporter $importer): void
    {
        $connection = StravaConnection::find($this->connectionId);

        if ($connection !== null) {
            $importer->backfill($connection, CarbonImmutable::now()->subDays(config('services.strava.backfill_days')));
        }
    }
}
