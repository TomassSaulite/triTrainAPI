<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Models\WorkoutTemplate;

/**
 * Everyone can read the system library; personal templates are private and
 * the system library is read-only.
 */
class WorkoutTemplatePolicy
{
    public function view(User $user, WorkoutTemplate $template): bool
    {
        return $template->isSystem() || $this->owns($user, $template);
    }

    public function update(User $user, WorkoutTemplate $template): bool
    {
        return $this->owns($user, $template);
    }

    public function delete(User $user, WorkoutTemplate $template): bool
    {
        return $this->owns($user, $template);
    }

    private function owns(User $user, WorkoutTemplate $template): bool
    {
        return $template->athlete_id !== null && $template->athlete_id === $user->athlete?->id;
    }
}
