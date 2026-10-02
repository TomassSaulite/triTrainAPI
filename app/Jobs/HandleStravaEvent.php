<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Integrations\Strava\StravaImporter;
use App\Models\StravaConnection;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Handles one Strava webhook event. Event bodies are not signed, so they are
 * only used as a hint: activity data is always fetched from the API with the
 * owner's token.
 *
 * @see https://developers.strava.com/docs/webhooks/
 */
class HandleStravaEvent implements ShouldQueue
{
    use Queueable;

    /**
     * @param  array<string, mixed>  $event
     */
    public function __construct(
        public readonly array $event,
    ) {}

    public function handle(StravaImporter $importer): void
    {
        $connection = StravaConnection::where('strava_athlete_id', (int) ($this->event['owner_id'] ?? 0))->first();

        if ($connection === null) {
            return;
        }

        $objectId = (int) ($this->event['object_id'] ?? 0);

        match ([$this->event['object_type'] ?? null, $this->event['aspect_type'] ?? null]) {
            ['activity', 'create'], ['activity', 'update'] => ImportStravaActivity::dispatch($connection->id, $objectId),
            ['activity', 'delete'] => $importer->delete($connection, $objectId),
            ['athlete', 'update'] => $this->handleAthleteUpdate($connection),
            default => null,
        };
    }

    /**
     * The athlete revoked access from Strava's side.
     */
    private function handleAthleteUpdate(StravaConnection $connection): void
    {
        if (($this->event['updates']['authorized'] ?? null) === 'false') {
            $connection->delete();
        }
    }
}
