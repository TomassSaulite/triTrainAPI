<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Engine\Race\LegPlan;
use App\Engine\Race\RaceStrategy;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin RaceStrategy
 */
class RaceStrategyResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'legs' => array_map(fn (LegPlan $leg) => [
                'sport' => $leg->sport,
                'distance_m' => $leg->distanceM,
                'target' => $leg->target === null ? null : [
                    'unit' => $leg->target->unit,
                    'easy' => $leg->target->easy,
                    'hard' => $leg->target->hard,
                    'target' => $leg->target->target,
                    'intensity' => round($leg->target->intensity, 3),
                ],
                'predicted_s' => $leg->predictedSeconds,
                'advice' => $leg->advice,
            ], $this->legs),
            'transitions_s' => $this->transitionSeconds,
            'finish_s' => $this->finishSeconds,
            'fueling' => [
                'before' => $this->fuelingBefore,
                'during' => $this->fuelingDuring,
            ],
            'missing' => $this->missing,
        ];
    }
}
