<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\Sport;
use App\Enums\WorkoutKind;
use App\Models\WorkoutTemplate;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<WorkoutTemplate>
 */
class WorkoutTemplateFactory extends Factory
{
    public function definition(): array
    {
        $name = 'Endurance ride '.fake()->unique()->numberBetween(1, 9999);

        return [
            'athlete_id' => null,
            'slug' => Str::slug($name),
            'name' => $name,
            'description' => null,
            'sport' => Sport::Bike,
            'kind' => WorkoutKind::Endurance,
            'phases' => ['base', 'build', 'peak', 'taper'],
            'min_s' => 2700,
            'max_s' => 10800,
            'intensity_factor' => 0.68,
            'structure' => [
                'steps' => [
                    ['type' => 'warmup', 'duration_s' => 600, 'target' => ['metric' => 'ftp_pct', 'low' => 0.5, 'high' => 0.6]],
                    ['type' => 'steady', 'duration_s' => 2400, 'target' => ['metric' => 'ftp_pct', 'low' => 0.65, 'high' => 0.75]],
                    ['type' => 'cooldown', 'duration_s' => 600, 'target' => ['metric' => 'ftp_pct', 'low' => 0.5, 'high' => 0.6]],
                ],
            ],
            'is_active' => true,
        ];
    }
}
