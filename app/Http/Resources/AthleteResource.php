<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Athlete;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Athlete
 */
class AthleteResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'timezone' => $this->timezone,
            'birth_year' => $this->birth_year,
            'weight_kg' => $this->weight_kg,
            'max_hr' => $this->max_hr,
            'experience' => $this->experience,
            'weekly_hours' => $this->weekly_hours,
            'weakest_sport' => $this->weakest_sport,
            'preferences' => $this->prefs->toArray(),
            'updated_at' => $this->updated_at,
        ];
    }
}
