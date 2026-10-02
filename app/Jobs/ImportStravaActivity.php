<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Integrations\Strava\StravaImporter;
use App\Models\StravaConnection;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ImportStravaActivity implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /**
     * @var list<int>
     */
    public array $backoff = [30, 120, 600];

    public function __construct(
        public readonly int $connectionId,
        public readonly int $stravaActivityId,
    ) {}

    public function uniqueId(): string
    {
        return "strava-activity-{$this->stravaActivityId}";
    }

    public function handle(StravaImporter $importer): void
    {
        $connection = StravaConnection::find($this->connectionId);

        if ($connection !== null) {
            $importer->import($connection, $this->stravaActivityId);
        }
    }
}
