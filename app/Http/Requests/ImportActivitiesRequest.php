<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\ActivitySource;
use App\Enums\Sport;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A batch of activities read on the device (Health Connect) or from FIT
 * files. external_id makes re-sending the same batch harmless.
 */
class ImportActivitiesRequest extends FormRequest
{
    public const int MAX_BATCH = 200;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'source' => ['required', Rule::enum(ActivitySource::class)->only([ActivitySource::HealthConnect, ActivitySource::Fit])],
            'activities' => ['required', 'array', 'min:1', 'max:'.self::MAX_BATCH],
            'activities.*.external_id' => ['required', 'string', 'max:255', 'distinct'],
            'activities.*.sport' => ['required', Rule::enum(Sport::class)->except([Sport::Brick])],
            'activities.*.name' => ['nullable', 'string', 'max:255'],
            'activities.*.started_at' => ['required', 'date', 'before_or_equal:now'],
            'activities.*.duration_s' => ['required', 'integer', 'between:60,86400'],
            'activities.*.distance_m' => ['nullable', 'integer', 'between:0,1000000'],
            'activities.*.avg_hr' => ['nullable', 'integer', 'between:40,230'],
            'activities.*.np_w' => ['nullable', 'integer', 'between:0,2000'],
            'activities.*.best_20min_power_w' => ['nullable', 'integer', 'between:0,2000'],
            'activities.*.avg_pace' => ['nullable', 'numeric', 'between:30,1800'],
            'activities.*.fit_path' => ['nullable', 'string', 'max:255'],
        ];
    }
}
