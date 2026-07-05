<?php

namespace Tests\Feature;

use Tests\TestCase;

class RootRouteTest extends TestCase
{
    public function test_the_root_returns_api_info_not_the_laravel_welcome_splash(): void
    {
        $res = $this->get('/');
        $res->assertOk();
        $res->assertHeader('content-type', 'application/json');
        $res->assertJsonPath('status', 'ok');
        $res->assertDontSee('Laravel'); // the welcome splash is gone
        $res->assertJsonMissingPath('admin'); // don't advertise the admin panel URL
        $res->assertDontSee('/admin');
    }

    public function test_the_health_route_is_up(): void
    {
        $this->get('/up')->assertOk();
    }
}
