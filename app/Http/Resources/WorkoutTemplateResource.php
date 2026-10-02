<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\WorkoutTemplate;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin WorkoutTemplate
 */
class WorkoutTemplateResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'name' => $this->name,
            'description' => $this->description,
            'sport' => $this->sport,
            'kind' => $this->kind,
            'phases' => $this->phases,
            'distances' => $this->distances,
            'min_s' => $this->min_s,
            'max_s' => $this->max_s,
            'intensity_factor' => $this->intensity_factor,
            'structure' => $this->structure,
            'is_system' => $this->isSystem(),
            'is_active' => $this->is_active,
        ];
    }
}
