<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GeoProxyTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_proxies_overpass_and_caches_the_result(): void
    {
        Cache::flush();
        Http::fake([
            '*' => Http::response(['elements' => [['id' => 1, 'type' => 'node']]], 200),
        ]);

        $ql = '[out:json];node["amenity"="cinema"](around:1000,47.5,19.0);out center;';

        $first = $this->postJson('/api/geo/overpass', ['ql' => $ql]);
        $first->assertOk()->assertJsonPath('elements.0.id', 1);

        // A second identical query is served from cache — Overpass is hit only once.
        $this->postJson('/api/geo/overpass', ['ql' => $ql])->assertOk()->assertJsonPath('elements.0.id', 1);

        Http::assertSentCount(1);
    }

    public function test_it_returns_empty_when_every_mirror_fails(): void
    {
        Cache::flush();
        Http::fake(['*' => Http::response('rate limited', 429)]);

        $this->postJson('/api/geo/overpass', ['ql' => '[out:json];node;out;'])
            ->assertStatus(502)
            ->assertJsonPath('elements', []);
    }

    public function test_it_validates_the_query(): void
    {
        $this->postJson('/api/geo/overpass', [])->assertStatus(422);
        $this->postJson('/api/geo/overpass', ['ql' => str_repeat('x', 8001)])->assertStatus(422);
    }
}
