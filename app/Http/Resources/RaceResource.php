<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Race;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Race
 */
class RaceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'distance' => $this->distance,
            'date' => $this->date->toDateString(),
            'priority' => $this->priority,
            'days_to_go' => (int) ($request->user()?->athlete?->today() ?? today())->diffInDays($this->date, false),
        ];
    }
}
