<?php

declare(strict_types=1);

namespace App\Integrations\Strava;

use App\Enums\ActivitySource;
use App\Enums\Sport;
use Carbon\CarbonImmutable;

/**
 * Maps a Strava activity onto our activity columns. Sports we do not plan for
 * (walks, ski, yoga...) map to null and are not imported.
 */
class StravaActivityMapper
{
    private const array SPORTS = [
        'Ride' => Sport::Bike,
        'VirtualRide' => Sport::Bike,
        'GravelRide' => Sport::Bike,
        'MountainBikeRide' => Sport::Bike,
        'Run' => Sport::Run,
        'TrailRun' => Sport::Run,
        'VirtualRun' => Sport::Run,
        'Swim' => Sport::Swim,
        'WeightTraining' => Sport::Strength,
        'Workout' => Sport::Strength,
    ];

    public function sport(array $activity): ?Sport
    {
        return self::SPORTS[$activity['sport_type'] ?? $activity['type'] ?? ''] ?? null;
    }

    /**
     * @param  array<string, mixed>  $activity
     * @return array<string, mixed>|null
     */
    public function attributes(array $activity): ?array
    {
        $sport = $this->sport($activity);

        if ($sport === null || ! isset($activity['id'], $activity['start_date'], $activity['moving_time'])) {
            return null;
        }

        $speed = (float) ($activity['average_speed'] ?? 0);
        $hasPower = ($activity['device_watts'] ?? false) && isset($activity['weighted_average_watts']);

        return [
            'source' => ActivitySource::Strava,
            'external_id' => (string) $activity['id'],
            'sport' => $sport,
            'name' => $activity['name'] ?? null,
            'started_at' => CarbonImmutable::parse($activity['start_date']),
            'duration_s' => (int) $activity['moving_time'],
            'distance_m' => isset($activity['distance']) ? (int) round($activity['distance']) : null,
            'avg_hr' => isset($activity['average_heartrate']) ? (int) round($activity['average_heartrate']) : null,
            'np_w' => $hasPower ? (int) $activity['weighted_average_watts'] : null,
            'avg_pace' => match (true) {
                $speed <= 0 => null,
                $sport === Sport::Run => round(1000 / $speed, 2),
                $sport === Sport::Swim => round(100 / $speed, 2),
                default => null,
            },
        ];
    }
}
