<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Engine\Planning\CoachPreferences;
use App\Enums\Experience;
use App\Models\Athlete;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Athlete>
 */
class AthleteFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'timezone' => 'UTC',
            'birth_year' => fake()->numberBetween(1970, 2000),
            'weight_kg' => fake()->randomFloat(1, 55, 90),
            'max_hr' => fake()->numberBetween(170, 195),
            'experience' => Experience::Intermediate,
            'weekly_hours' => 8.0,
            'weakest_sport' => null,
            'prefs' => new CoachPreferences,
        ];
    }

    public function novice(): static
    {
        return $this->state(['experience' => Experience::Novice, 'weekly_hours' => 6.0]);
    }

    public function advanced(): static
    {
        return $this->state(['experience' => Experience::Advanced, 'weekly_hours' => 12.0]);
    }
}
