<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Activity;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Activity
 */
class ActivityResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'source' => $this->source,
            'external_id' => $this->external_id,
            'sport' => $this->sport,
            'name' => $this->name,
            'started_at' => $this->started_at,
            'duration_s' => $this->duration_s,
            'distance_m' => $this->distance_m,
            'avg_hr' => $this->avg_hr,
            'np_w' => $this->np_w,
            'best_20min_power_w' => $this->best_20min_power_w,
            'avg_pace' => $this->avg_pace,
            'tss' => $this->tss,
            'tss_method' => $this->tss_method,
            'intensity_factor' => $this->intensity_factor,
            'planned_workout_id' => $this->whenLoaded('plannedWorkout', fn () => $this->plannedWorkout?->id),
            'feedback' => $this->whenLoaded('feedback', fn () => $this->feedback === null ? null : new SessionFeedbackResource($this->feedback)),
        ];
    }
}
