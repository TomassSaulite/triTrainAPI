<?php

declare(strict_types=1);

namespace App\Services\Planning;

use App\Engine\Planning\Template;
use App\Engine\Planning\TemplateLibrary;
use App\Engine\Structure\WorkoutStructure;
use App\Enums\PhaseType;
use App\Enums\RaceDistance;
use App\Models\Athlete;
use App\Models\WorkoutTemplate;

class TemplateRepository
{
    /**
     * The active system library plus the athlete's own templates.
     */
    public function libraryFor(Athlete $athlete): TemplateLibrary
    {
        $templates = WorkoutTemplate::query()
            ->availableTo($athlete)
            ->where('is_active', true)
            ->get()
            ->map(fn (WorkoutTemplate $t) => new Template(
                id: $t->id,
                slug: $t->slug,
                name: $t->name,
                sport: $t->sport,
                kind: $t->kind,
                phases: array_map(PhaseType::from(...), $t->phases),
                minSeconds: $t->min_s,
                maxSeconds: $t->max_s,
                structure: WorkoutStructure::fromArray($t->structure),
                isPersonal: ! $t->isSystem(),
                distances: $t->distances === null ? null : array_map(RaceDistance::from(...), $t->distances),
            ));

        return new TemplateLibrary($templates->values()->all());
    }
}
