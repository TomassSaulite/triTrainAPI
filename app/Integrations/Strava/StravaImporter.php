<?php

declare(strict_types=1);

namespace App\Integrations\Strava;

use App\Enums\ActivitySource;
use App\Models\Activity;
use App\Models\StravaConnection;
use App\Services\ActivityPipeline;
use Carbon\CarbonImmutable;

/**
 * Brings Strava activities into the activity pipeline.
 */
class StravaImporter
{
    /**
     * Upper bound on pages fetched in one backfill (100 activities each).
     */
    public const int MAX_BACKFILL_PAGES = 10;

    public function __construct(
        private readonly StravaClient $client,
        private readonly StravaActivityMapper $mapper,
        private readonly ActivityPipeline $pipeline,
    ) {}

    /**
     * Imports (or re-imports after an edit) a single activity.
     */
    public function import(StravaConnection $connection, int $stravaActivityId): ?Activity
    {
        $attributes = $this->mapper->attributes($this->client->activity($connection, $stravaActivityId));

        if ($attributes === null) {
            return null;
        }

        $activity = $this->upsert($connection, $attributes);
        $this->pipeline->process($activity);

        return $activity;
    }

    /**
     * Imports recent history so fitness starts from real data, not an estimate.
     *
     * @return int number of activities imported
     */
    public function backfill(StravaConnection $connection, CarbonImmutable $since): int
    {
        $activities = [];

        for ($page = 1; $page <= self::MAX_BACKFILL_PAGES; $page++) {
            $batch = $this->client->activitiesSince($connection, $since->getTimestamp(), $page);

            foreach ($batch as $summary) {
                $attributes = $this->mapper->attributes($summary);

                if ($attributes !== null) {
                    $activities[] = $this->upsert($connection, $attributes);
                }
            }

            if (count($batch) < 100) {
                break;
            }
        }

        $this->pipeline->processMany($connection->athlete, $activities);

        return count($activities);
    }

    public function delete(StravaConnection $connection, int $stravaActivityId): void
    {
        $activity = $connection->athlete->activities()
            ->where('source', ActivitySource::Strava)
            ->where('external_id', (string) $stravaActivityId)
            ->first();

        if ($activity !== null) {
            $this->pipeline->remove($activity);
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function upsert(StravaConnection $connection, array $attributes): Activity
    {
        $activity = $connection->athlete->activities()
            ->where('source', ActivitySource::Strava)
            ->where('external_id', $attributes['external_id'])
            ->first() ?? $connection->athlete->activities()->make();

        $activity->fill($attributes);

        if ($activity->exists && $activity->isDirty(['started_at', 'sport'])) {
            $this->pipeline->detach($activity);
        }

        // Re-score from the new numbers.
        $activity->tss = null;
        $activity->save();

        return $activity;
    }
}
