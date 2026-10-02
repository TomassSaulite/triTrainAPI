<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\AvailabilityOverride;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin AvailabilityOverride
 */
class AvailabilityOverrideResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'date' => $this->date->toDateString(),
            'available_minutes' => $this->available_minutes,
            'note' => $this->note,
            'easy_only' => $this->easy_only,
        ];
    }
}
