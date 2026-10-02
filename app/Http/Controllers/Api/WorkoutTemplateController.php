<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Engine\Structure\StructureAnalyzer;
use App\Engine\Structure\WorkoutStructure;
use App\Enums\Sport;
use App\Enums\WorkoutKind;
use App\Http\Controllers\Controller;
use App\Http\Requests\WorkoutTemplateRequest;
use App\Http\Resources\WorkoutTemplateResource;
use App\Models\WorkoutTemplate;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * The workout library: the shared system templates plus the athlete's own,
 * which the generator prefers when they fit a slot.
 */
class WorkoutTemplateController extends Controller
{
    public function __construct(
        private readonly StructureAnalyzer $analyzer,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'sport' => ['sometimes', Rule::enum(Sport::class)],
            'kind' => ['sometimes', Rule::enum(WorkoutKind::class)],
            'mine' => ['sometimes', 'boolean'],
        ]);
        $athlete = $request->user()->athleteOrFail();

        $templates = WorkoutTemplate::query()
            ->when($request->boolean('mine'), fn ($q) => $q->where('athlete_id', $athlete->id), fn ($q) => $q->availableTo($athlete))
            ->when($filters['sport'] ?? null, fn ($q, $sport) => $q->where('sport', $sport))
            ->when($filters['kind'] ?? null, fn ($q, $kind) => $q->where('kind', $kind))
            ->orderBy('sport')
            ->orderBy('kind')
            ->orderBy('name')
            ->get();

        return WorkoutTemplateResource::collection($templates);
    }

    public function show(WorkoutTemplate $workoutTemplate): WorkoutTemplateResource
    {
        Gate::authorize('view', $workoutTemplate);

        return new WorkoutTemplateResource($workoutTemplate);
    }

    public function store(WorkoutTemplateRequest $request): WorkoutTemplateResource
    {
        $template = $request->user()->athleteOrFail()->workoutTemplates()->make([
            'slug' => Str::slug($request->string('name')->toString()).'-'.Str::lower(Str::random(6)),
        ]);

        $this->save($template, $request->validated());

        return new WorkoutTemplateResource($template);
    }

    public function update(WorkoutTemplateRequest $request, WorkoutTemplate $workoutTemplate): WorkoutTemplateResource
    {
        Gate::authorize('update', $workoutTemplate);

        $this->save($workoutTemplate, $request->validated());

        return new WorkoutTemplateResource($workoutTemplate);
    }

    public function destroy(WorkoutTemplate $workoutTemplate): Response
    {
        Gate::authorize('delete', $workoutTemplate);

        $workoutTemplate->delete();

        return response()->noContent();
    }

    /**
     * Stores the normalized structure and recomputes the intensity factor so
     * the generator can size the workout.
     *
     * @param  array<string, mixed>  $data
     */
    private function save(WorkoutTemplate $template, array $data): void
    {
        $template->fill($data);

        $structure = WorkoutStructure::fromArray($template->structure);
        $template->structure = $structure->toArray();
        $template->intensity_factor = $this->analyzer->analyze($structure, $template->sport)->intensityFactor;

        $template->save();
    }
}
