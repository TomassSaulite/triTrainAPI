<?php

declare(strict_types=1);

namespace App\Casts;

use App\Engine\Planning\CoachPreferences;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * @implements CastsAttributes<CoachPreferences, CoachPreferences|array<string, mixed>>
 */
class AsCoachPreferences implements CastsAttributes
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): CoachPreferences
    {
        $data = is_string($value) ? json_decode($value, true, flags: JSON_THROW_ON_ERROR) : [];

        return CoachPreferences::fromArray($data ?? []);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_array($value)) {
            $value = CoachPreferences::fromArray($value);
        }

        if (! $value instanceof CoachPreferences) {
            throw new InvalidArgumentException('Preferences must be an array or a CoachPreferences instance.');
        }

        return json_encode($value->toArray(), JSON_THROW_ON_ERROR);
    }
}
