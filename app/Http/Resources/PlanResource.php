<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Plan;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Plan
 */
class PlanResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status,
            'version' => $this->version,
            'generator_version' => $this->generator_version,
            'start_date' => $this->start_date->toDateString(),
            'starting_ctl' => $this->starting_ctl,
            'target_ctl' => $this->target_ctl,
            'warnings' => $this->warnings ?? [],
            'race' => new RaceResource($this->whenLoaded('race')),
            'phases' => PlanPhaseResource::collection($this->whenLoaded('phases')),
            'weeks' => PlanWeekResource::collection($this->whenLoaded('weeks')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
