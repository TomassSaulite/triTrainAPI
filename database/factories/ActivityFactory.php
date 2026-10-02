<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ActivitySource;
use App\Enums\Sport;
use App\Models\Activity;
use App\Models\Athlete;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Activity>
 */
class ActivityFactory extends Factory
{
    public function definition(): array
    {
        return [
            'athlete_id' => Athlete::factory(),
            'source' => ActivitySource::Manual,
            'external_id' => null,
            'sport' => Sport::Run,
            'name' => 'Easy run',
            'started_at' => now()->subDay()->setTime(7, 0),
            'duration_s' => 3600,
            'distance_m' => 10000,
            'avg_hr' => 145,
            'np_w' => null,
            'avg_pace' => 360,
        ];
    }
}
