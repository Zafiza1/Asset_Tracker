<?php

namespace Tests\Feature;

use Tests\TestCase;

class ExampleTest extends TestCase
{
    public function test_health_endpoint()
    {
        $response = $this->get('/api/health');

        $response->assertStatus(200)
                 ->assertJson([
                     'status' => 'healthy',
                 ]);
    }

    public function test_root_endpoint()
    {
        $response = $this->get('/');

        $response->assertStatus(200)
                 ->assertJson([
                     'name' => 'Asset Tracker PaaS',
                 ]);
    }
}
