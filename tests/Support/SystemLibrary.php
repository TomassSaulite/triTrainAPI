<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Engine\Planning\Template;
use App\Engine\Planning\TemplateLibrary;
use App\Engine\Structure\WorkoutStructure;
use App\Enums\PhaseType;
use App\Enums\RaceDistance;
use App\Enums\Sport;
use App\Enums\WorkoutKind;

/**
 * The seeded system library as an engine TemplateLibrary, read straight from
 * the data file so engine tests need no database.
 */
final class SystemLibrary
{
    public static function load(): TemplateLibrary
    {
        $entries = require __DIR__.'/../../database/data/workout_templates.php';

        return new TemplateLibrary(array_map(fn (array $e, int $i) => new Template(
            id: $i + 1,
            slug: $e['slug'],
            name: $e['name'],
            sport: Sport::from($e['sport']),
            kind: WorkoutKind::from($e['kind']),
            phases: array_map(PhaseType::from(...), $e['phases']),
            minSeconds: $e['min_s'],
            maxSeconds: $e['max_s'],
            structure: WorkoutStructure::fromArray(['steps' => $e['steps']]),
            distances: isset($e['distances']) ? array_map(RaceDistance::from(...), $e['distances']) : null,
        ), $entries, array_keys($entries)));
    }
}
