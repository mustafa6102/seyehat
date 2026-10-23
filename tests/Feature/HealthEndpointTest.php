<?php

namespace Tests\Feature;

use Tests\TestCase;

class HealthEndpointTest extends TestCase
{
    public function test_versioned_health_endpoint_responds_without_exposing_an_application_payload(): void
    {
        $this->getJson('/api/v1/health')
            ->assertNoContent();
    }
}
