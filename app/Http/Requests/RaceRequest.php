<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\RaceDistance;
use App\Enums\RacePriority;
use App\Models\Race;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class RaceRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $required = $this->isMethod('POST') ? 'required' : 'sometimes';

        return [
            'name' => [$required, 'string', 'max:255'],
            'distance' => [$required, Rule::enum(RaceDistance::class)],
            'date' => [$required, 'date', 'after:today'],
            'priority' => ['sometimes', Rule::enum(RacePriority::class)],
        ];
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                /** @var Race|null $race */
                $race = $this->route('race');
                $distance = RaceDistance::tryFrom((string) $this->input('distance', $race?->distance->value));
                $priority = $this->input('priority', $race?->priority->value ?? $this->defaultPriority($distance)->value);

                if ($distance !== null && ! $distance->isTriathlon() && $priority === RacePriority::A->value) {
                    $validator->errors()->add('priority', 'Only a triathlon can be your A race. Add a running race as a B or C race inside your plan.');
                }
            },
        ];
    }

    /**
     * New triathlons default to the A race; other races to a B race inside it.
     */
    public function defaultPriority(?RaceDistance $distance): RacePriority
    {
        return $distance === null || $distance->isTriathlon() ? RacePriority::A : RacePriority::B;
    }
}
