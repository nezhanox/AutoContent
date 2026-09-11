<?php

namespace Tests\Feature;

use Tests\TestCase;

class HealthCheckTest extends TestCase
{
    public function test_health_check_returns_200(): void
    {
        $response = $this->get('/up');

        $response->assertStatus(200);
    }
}
