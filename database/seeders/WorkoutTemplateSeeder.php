<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Engine\Structure\StructureAnalyzer;
use App\Engine\Structure\WorkoutStructure;
use App\Enums\Sport;
use App\Models\WorkoutTemplate;
use Illuminate\Database\Seeder;

/**
 * Installs or refreshes the system workout library. Idempotent, so it is safe
 * to run on every deploy.
 */
class WorkoutTemplateSeeder extends Seeder
{
    public function run(StructureAnalyzer $analyzer): void
    {
        /** @var list<array<string, mixed>> $library */
        $library = require database_path('data/workout_templates.php');

        foreach ($library as $entry) {
            $structure = WorkoutStructure::fromArray(['steps' => $entry['steps']]);
            $analysis = $analyzer->analyze($structure, Sport::from($entry['sport']));

            $template = WorkoutTemplate::query()->whereNull('athlete_id')->where('slug', $entry['slug'])->first()
                ?? new WorkoutTemplate(['slug' => $entry['slug']]);

            $template->fill([
                'name' => $entry['name'],
                'description' => $entry['description'],
                'sport' => $entry['sport'],
                'kind' => $entry['kind'],
                'phases' => $entry['phases'],
                'distances' => $entry['distances'] ?? null,
                'min_s' => $entry['min_s'],
                'max_s' => $entry['max_s'],
                'intensity_factor' => $analysis->intensityFactor,
                'structure' => $structure->toArray(),
                'is_active' => true,
            ])->save();
        }
    }
}
