<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\ThresholdMetric;
use App\Enums\ThresholdSource;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreThresholdRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'metric' => ['required', Rule::enum(ThresholdMetric::class)],
            'value' => ['required', 'numeric'],
            'tested_at' => ['sometimes', 'date', 'before_or_equal:today'],
            'source' => ['sometimes', Rule::enum(ThresholdSource::class)->except([ThresholdSource::AutoDetected])],
        ];
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $metric = ThresholdMetric::tryFrom((string) $this->input('metric'));

                if ($metric === null || $validator->errors()->has('value')) {
                    return;
                }

                [$min, $max] = $metric->plausibleRange();
                $value = (float) $this->input('value');

                if ($value < $min || $value > $max) {
                    $validator->errors()->add('value', "A {$metric->value} of {$value} is outside the plausible range {$min}-{$max}.");
                }
            },
        ];
    }

    public function metric(): ThresholdMetric
    {
        return ThresholdMetric::from($this->string('metric')->toString());
    }
}
