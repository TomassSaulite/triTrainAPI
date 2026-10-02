<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\Experience;
use App\Enums\Sport;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Creates or updates the athlete profile, including the coach preferences.
 * When the profile already exists every field is optional (partial update).
 */
class UpsertAthleteRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $required = $this->user()?->athlete === null ? 'required' : 'sometimes';
        $weekday = ['integer', 'between:1,7'];

        return [
            'timezone' => ['sometimes', 'timezone:all'],
            'birth_year' => ['nullable', 'integer', 'between:1920,'.now()->year],
            'weight_kg' => ['nullable', 'numeric', 'between:30,200'],
            'max_hr' => ['nullable', 'integer', 'between:120,230'],
            'experience' => [$required, Rule::enum(Experience::class)],
            'weekly_hours' => [$required, 'numeric', 'between:2,30'],
            'weakest_sport' => ['nullable', Rule::enum(Sport::class)->only(Sport::disciplines())],

            'preferences' => ['sometimes', 'array'],
            'preferences.long_ride_day' => ['sometimes', ...$weekday],
            'preferences.long_run_day' => ['sometimes', 'nullable', ...$weekday],
            'preferences.pool_days' => ['sometimes', 'array', 'max:7'],
            'preferences.pool_days.*' => ['distinct', ...$weekday],
            'preferences.rest_days' => ['sometimes', 'array', 'max:3'],
            'preferences.rest_days.*' => ['distinct', ...$weekday],
            'preferences.day_limits_minutes' => ['sometimes', 'array'],
            'preferences.day_limits_minutes.*' => ['integer', 'between:0,600'],
            'preferences.recovery_week_every' => ['sometimes', 'nullable', 'integer', 'between:2,5'],
            'preferences.max_ramp_rate' => ['sometimes', 'numeric', 'between:1,8'],
            'preferences.target_ctl' => ['sometimes', 'nullable', 'numeric', 'between:20,150'],
            'preferences.sport_share' => ['sometimes', 'nullable', 'array:swim,bike,run'],
            'preferences.sport_share.*' => ['numeric', 'between:0.05,0.8'],
            'preferences.bricks' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $prefs = $this->mergedPreferences();

                if (array_diff(array_keys($prefs['day_limits_minutes'] ?? []), range(1, 7)) !== []) {
                    $validator->errors()->add('preferences.day_limits_minutes', 'Day limits must be keyed by ISO weekday (1-7).');
                }

                if (in_array($prefs['long_ride_day'] ?? null, $prefs['rest_days'] ?? [], false)) {
                    $validator->errors()->add('preferences.rest_days', 'The long ride day cannot be a rest day.');
                }

                if (isset($prefs['long_run_day']) && in_array($prefs['long_run_day'], $prefs['rest_days'] ?? [], false)) {
                    $validator->errors()->add('preferences.rest_days', 'The long run day cannot be a rest day.');
                }

                $share = $prefs['sport_share'] ?? null;

                if (is_array($share) && (count($share) !== 3 || abs(array_sum($share) - 1.0) > 0.01)) {
                    $validator->errors()->add('preferences.sport_share', 'Give a share for swim, bike and run that adds up to 1.');
                }
            },
        ];
    }

    /**
     * The athlete's stored preferences with this request's changes applied.
     *
     * @return array<string, mixed>
     */
    public function mergedPreferences(): array
    {
        $current = $this->user()?->athlete?->prefs->toArray() ?? [];
        $current['day_limits_minutes'] = (array) ($current['day_limits_minutes'] ?? []);

        return array_replace($current, (array) $this->input('preferences', []));
    }
}
