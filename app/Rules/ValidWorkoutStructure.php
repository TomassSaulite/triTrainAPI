<?php

declare(strict_types=1);

namespace App\Rules;

use App\Engine\Structure\InvalidStructure;
use App\Engine\Structure\WorkoutStructure;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class ValidWorkoutStructure implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_array($value)) {
            $fail('The :attribute must be an object with a steps list.');

            return;
        }

        try {
            WorkoutStructure::fromArray($value);
        } catch (InvalidStructure $e) {
            $fail("The :attribute is invalid at {$e->getMessage()}");
        }
    }
}
