<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\RaceDistance;
use App\Enums\RacePriority;
use App\Models\Athlete;
use App\Models\Race;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Race>
 */
class RaceFactory extends Factory
{
    public function definition(): array
    {
        return [
            'athlete_id' => Athlete::factory(),
            'name' => fake()->city().' 70.3',
            'distance' => RaceDistance::Half,
            'date' => now()->addWeeks(20)->toDateString(),
            'priority' => RacePriority::A,
        ];
    }
}
