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

        $first = $this->postJson('/api/v1/geo/overpass', ['ql' => $ql]);
        $first->assertOk()->assertJsonPath('elements.0.id', 1);

        // A second identical query is served from cache — Overpass is hit only once.
        $this->postJson('/api/v1/geo/overpass', ['ql' => $ql])->assertOk()->assertJsonPath('elements.0.id', 1);

        Http::assertSentCount(1);
    }

    public function test_it_returns_empty_when_every_mirror_fails(): void
    {
        Cache::flush();
        Http::fake(['*' => Http::response('rate limited', 429)]);

        $this->postJson('/api/v1/geo/overpass', ['ql' => '[out:json];node;out;'])
            ->assertStatus(502)
            ->assertJsonPath('elements', []);
    }

    public function test_it_validates_the_query(): void
    {
        $this->postJson('/api/v1/geo/overpass', [])->assertStatus(422);
        $this->postJson('/api/v1/geo/overpass', ['ql' => str_repeat('x', 8001)])->assertStatus(422);
    }

    public function test_it_retries_a_transient_5xx_then_succeeds(): void
    {
        Cache::flush();
        // First attempt 502 (transient), retry on the same mirror succeeds.
        Http::fake(['*' => Http::sequence()
            ->push('bad gateway', 502)
            ->push(['elements' => [['id' => 7, 'type' => 'node']]], 200)]);

        $this->postJson('/api/v1/geo/overpass', ['ql' => '[out:json];node;out;'])
            ->assertOk()->assertJsonPath('elements.0.id', 7);

        Http::assertSentCount(2);
    }

    public function test_it_fails_fast_on_a_4xx_without_retrying(): void
    {
        Cache::flush();
        Http::fake(['*' => Http::response('bad request', 400)]);

        $this->postJson('/api/v1/geo/overpass', ['ql' => '[out:json];node;out;'])
            ->assertStatus(502)->assertJsonPath('elements', []);

        // A 4xx is our bad query — no retry, no second mirror.
        Http::assertSentCount(1);
    }

    public function test_it_serves_stale_data_when_overpass_goes_down(): void
    {
        Cache::flush();
        $ql = '[out:json];node["amenity"="cinema"](around:1000,47.5,19.0);out center;';

        // A first success caches a fresh + a longer-lived stale copy.
        Http::fake(['*' => Http::response(['elements' => [['id' => 1, 'type' => 'node']]], 200)]);
        $this->postJson('/api/v1/geo/overpass', ['ql' => $ql])->assertOk();

        // Drop the fresh copy and simulate an outage: the stale copy is served, not a 502.
        Cache::forget('overpass_ql:'.sha1($ql));
        Http::fake(['*' => Http::response('service unavailable', 503)]);

        $this->postJson('/api/v1/geo/overpass', ['ql' => $ql])
            ->assertOk()->assertJsonPath('elements.0.id', 1);
    }
}
