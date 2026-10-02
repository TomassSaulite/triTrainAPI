<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\PlanWeek;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin PlanWeek
 */
class PlanWeekResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'week_index' => $this->week_index,
            'start_date' => $this->start_date->toDateString(),
            'phase' => $this->whenLoaded('phase', fn () => $this->phase->type),
            'is_recovery' => $this->is_recovery,
            'target_tss' => $this->target_tss,
            'target_hours' => $this->target_hours,
            'planned_tss' => $this->whenHas('planned_tss', fn ($tss) => round((float) $tss, 1)),
            'workouts' => PlannedWorkoutResource::collection($this->whenLoaded('workouts')),
        ];
    }
}
