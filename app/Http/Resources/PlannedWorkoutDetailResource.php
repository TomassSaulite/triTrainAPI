<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\PlannedWorkout;
use Illuminate\Http\Request;

/**
 * A single planned workout with its steps, both as stored (relative to
 * thresholds) and resolved against the athlete's current thresholds.
 *
 * @mixin PlannedWorkout
 */
class PlannedWorkoutDetailResource extends PlannedWorkoutResource
{
    /**
     * @param  array<string, mixed>|null  $resolved
     */
    public function __construct(PlannedWorkout $workout, private readonly ?array $resolved)
    {
        parent::__construct($workout);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            ...parent::toArray($request),
            'structure' => $this->structure,
            'resolved_structure' => $this->resolved,
        ];
    }
}
