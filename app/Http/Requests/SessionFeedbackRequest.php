<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\FeelDimension;
use Illuminate\Foundation\Http\FormRequest;

class SessionFeedbackRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rating = ['nullable', 'integer', 'between:'.FeelDimension::BEST.','.FeelDimension::WORST];

        return [
            'rpe' => ['required', 'integer', 'between:1,10'],
            ...array_fill_keys(array_column(FeelDimension::cases(), 'value'), $rating),
            'pain' => ['sometimes', 'boolean'],
            'pain_area' => ['nullable', 'string', 'max:60', 'exclude_unless:pain,true'],
            'note' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
