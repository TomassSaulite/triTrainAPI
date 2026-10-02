<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\PlannedWorkout;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A planned workout as listed in calendars and weeks: everything except the
 * step structure, which PlannedWorkoutDetailResource adds.
 *
 * @mixin PlannedWorkout
 */
class PlannedWorkoutResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'plan_id' => $this->plan_id,
            'parent_id' => $this->parent_id,
            'date' => $this->date->toDateString(),
            'sport' => $this->sport,
            'kind' => $this->kind,
            'is_key' => $this->is_key,
            'title' => $this->title,
            'target_duration_s' => $this->target_duration_s,
            'target_distance_m' => $this->target_distance_m,
            'target_tss' => $this->target_tss,
            'status' => $this->status,
            'compliance' => $this->compliance,
            'activity_id' => $this->activity_id,
            'template_id' => $this->workout_template_id,
            'children' => self::collection($this->whenLoaded('children')),
            'activity' => new ActivityResource($this->whenLoaded('activity')),
        ];
    }
}
