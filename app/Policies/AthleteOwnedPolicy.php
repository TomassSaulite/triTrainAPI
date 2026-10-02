<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Athletes only ever see and change their own records.
 */
abstract class AthleteOwnedPolicy
{
    public function view(User $user, Model $model): bool
    {
        return $this->owns($user, $model);
    }

    public function update(User $user, Model $model): bool
    {
        return $this->owns($user, $model);
    }

    public function delete(User $user, Model $model): bool
    {
        return $this->owns($user, $model);
    }

    protected function owns(User $user, Model $model): bool
    {
        $athleteId = $user->athlete?->id;

        return $athleteId !== null && $athleteId === $this->athleteIdOf($model);
    }

    protected function athleteIdOf(Model $model): ?int
    {
        return $model->getAttribute('athlete_id');
    }
}
