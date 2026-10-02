<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\PlannedWorkout;
use Illuminate\Database\Eloquent\Model;

class PlannedWorkoutPolicy extends AthleteOwnedPolicy
{
    protected function athleteIdOf(Model $model): ?int
    {
        /** @var PlannedWorkout $model */
        return $model->plan->athlete_id;
    }
}
