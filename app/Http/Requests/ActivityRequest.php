<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\Sport;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A manually entered (or app-imported) completed session.
 */
class ActivityRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $required = $this->isMethod('POST') ? 'required' : 'sometimes';

        return [
            'sport' => [$required, Rule::enum(Sport::class)->except([Sport::Brick])],
            'name' => ['nullable', 'string', 'max:255'],
            'started_at' => [$required, 'date', 'before_or_equal:now'],
            'duration_s' => [$required, 'integer', 'between:60,86400'],
            'distance_m' => ['nullable', 'integer', 'between:0,1000000'],
            'avg_hr' => ['nullable', 'integer', 'between:40,230'],
            'np_w' => ['nullable', 'integer', 'between:0,2000'],
            'best_20min_power_w' => ['nullable', 'integer', 'between:0,2000'],
            'avg_pace' => ['nullable', 'numeric', 'between:30,1800'],
            'tss' => ['nullable', 'numeric', 'between:0,1000'],
        ];
    }
}
