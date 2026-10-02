<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\PlanRevision;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin PlanRevision
 */
class PlanRevisionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'version' => $this->version,
            'reason' => $this->reason,
            'summary' => $this->summary,
            'changes' => $this->changes,
            'created_at' => $this->created_at,
        ];
    }
}
