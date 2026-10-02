<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\DailyLoad;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin DailyLoad
 */
class DailyLoadResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'date' => $this->date->toDateString(),
            'tss' => $this->tss,
            'ctl' => $this->ctl,
            'atl' => $this->atl,
            'tsb' => $this->tsb,
        ];
    }
}
