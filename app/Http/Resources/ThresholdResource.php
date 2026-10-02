<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Threshold;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Threshold
 */
class ThresholdResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'sport' => $this->sport,
            'metric' => $this->metric,
            'value' => $this->value,
            'tested_at' => $this->tested_at->toDateString(),
            'source' => $this->source,
            'created_at' => $this->created_at,
        ];
    }
}
