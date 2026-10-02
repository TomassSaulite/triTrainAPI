<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\ThresholdSuggestion;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ThresholdSuggestion
 */
class ThresholdSuggestionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'metric' => $this->metric,
            'current_value' => $this->current_value,
            'suggested_value' => $this->suggested_value,
            'rationale' => $this->rationale,
            'status' => $this->status,
            'activity_id' => $this->activity_id,
            'created_at' => $this->created_at,
            'resolved_at' => $this->resolved_at,
        ];
    }
}
