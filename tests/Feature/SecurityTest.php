<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

class SecurityTest extends TestCase
{
    public function test_protected_api_routes_reject_anonymous_requests(): void
    {
        $this->getJson('/api/v1/me')->assertUnauthorized();
    }

    public function test_strava_webhook_verification_rejects_an_invalid_token(): void
    {
        config(['services.strava.webhook_verify_token' => 'expected-token']);

        $this->getJson('/api/v1/webhooks/strava?hub_mode=subscribe&hub_verify_token=wrong-token&hub_challenge=challenge')
            ->assertForbidden();
    }
}
