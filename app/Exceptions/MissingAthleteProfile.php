<?php

declare(strict_types=1);

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * Thrown when a coaching endpoint is used before the athlete profile exists.
 */
class MissingAthleteProfile extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Create your athlete profile first (PUT /api/v1/athlete).');
    }

    public function render(): JsonResponse
    {
        return response()->json(['message' => $this->getMessage()], 409);
    }
}
