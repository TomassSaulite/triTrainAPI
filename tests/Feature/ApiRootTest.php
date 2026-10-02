<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

class ApiRootTest extends TestCase
{
    public function test_api_root_reports_name_and_version(): void
    {
        $this->getJson('/api/v1')
            ->assertOk()
            ->assertJson(['version' => 'v1']);
    }

    public function test_health_check_is_available(): void
    {
        $this->get('/up')->assertOk();
    }
}
