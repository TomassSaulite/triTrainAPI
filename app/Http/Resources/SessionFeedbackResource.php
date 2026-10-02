<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\SessionFeedback;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin SessionFeedback
 */
class SessionFeedbackResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'activity_id' => $this->activity_id,
            'rpe' => $this->rpe,
            'muscles' => $this->muscles,
            'breathing' => $this->breathing,
            'energy' => $this->energy,
            'mood' => $this->mood,
            'pain' => $this->pain,
            'pain_area' => $this->pain_area,
            'note' => $this->note,
            'updated_at' => $this->updated_at,
        ];
    }
}
