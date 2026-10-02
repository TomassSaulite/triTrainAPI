<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\PhaseType;
use App\Enums\RaceDistance;
use App\Enums\Sport;
use App\Enums\WorkoutKind;
use App\Models\WorkoutTemplate;
use App\Rules\ValidWorkoutStructure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class WorkoutTemplateRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $required = $this->isMethod('POST') ? 'required' : 'sometimes';
        /** @var WorkoutTemplate|null $template */
        $template = $this->route('workout_template');

        return [
            'name' => [$required, 'string', 'max:255'],
            'slug' => [
                'sometimes', 'string', 'max:64', 'alpha_dash',
                Rule::unique('workout_templates')
                    ->where('athlete_id', $this->user()->athleteOrFail()->id)
                    ->ignore($template?->id),
            ],
            'description' => ['nullable', 'string', 'max:2000'],
            'sport' => [$required, Rule::enum(Sport::class)->only(Sport::disciplines())],
            'kind' => [$required, Rule::enum(WorkoutKind::class)],
            'phases' => [$required, 'array', 'min:1'],
            'phases.*' => ['distinct', Rule::enum(PhaseType::class)],
            'distances' => ['sometimes', 'nullable', 'array', 'min:1'],
            'distances.*' => ['distinct', Rule::enum(RaceDistance::class)],
            'min_s' => [$required, 'integer', 'between:600,28800'],
            'max_s' => [$required, 'integer', 'between:600,28800', 'gte:min_s'],
            'structure' => [$required, new ValidWorkoutStructure],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
