<?php

declare(strict_types=1);

namespace App\Integrations\Strava;

use Carbon\CarbonImmutable;

final readonly class StravaToken
{
    public function __construct(
        public string $accessToken,
        public string $refreshToken,
        public CarbonImmutable $expiresAt,
        public ?int $stravaAthleteId = null,
    ) {}

    /**
     * @param  array<string, mixed>  $payload  Strava's token response
     */
    public static function fromResponse(array $payload): self
    {
        return new self(
            accessToken: (string) $payload['access_token'],
            refreshToken: (string) $payload['refresh_token'],
            expiresAt: CarbonImmutable::createFromTimestamp((int) $payload['expires_at']),
            stravaAthleteId: isset($payload['athlete']['id']) ? (int) $payload['athlete']['id'] : null,
        );
    }
}
