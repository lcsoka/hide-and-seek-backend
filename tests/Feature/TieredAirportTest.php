<?php

namespace Tests\Feature;

use App\Game\Geo\OverpassMapDataSource;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The airport feature is tiered: tier 1 is a real, recognisable airport (scheduled service /
 * classified type), tier 2 any registered aerodrome. The first tier with a hit in range wins —
 * so Budapest resolves to Ferihegy, but small cities without a real airport still get an answer.
 */
class TieredAirportTest extends TestCase
{
    /** Fake the Overpass mirror(s) with a per-query responder (keyed off the QL text). */
    private function fakeOverpass(callable $responder): void
    {
        Cache::flush(); // clear any cached result / all_down short-circuit from a prior test
        config(['game.overpass.endpoints' => ['http://overpass.test/api']]);
        Http::fake(fn ($request) => $responder((string) ($request->data()['data'] ?? '')));
    }

    /** True when the query targets tier 1 (real airports: iata or a classified aerodrome type). */
    private function isRealAirportTier(string $ql): bool
    {
        return str_contains($ql, 'aerodrome:type') || str_contains($ql, 'iata');
    }

    private function aerodrome(string $name, float $lat, float $lng): array
    {
        return ['type' => 'node', 'id' => crc32($name), 'lat' => $lat, 'lon' => $lng, 'tags' => ['name' => $name]];
    }

    public function test_a_real_airport_is_preferred_even_when_an_airfield_is_nearer(): void
    {
        // Tier 1 has Ferihegy (~17 km); tier 2 would offer a nearer glider strip — tier 1 must win.
        $this->fakeOverpass(fn (string $ql) => $this->isRealAirportTier($ql)
            ? Http::response(['elements' => [$this->aerodrome('Ferihegy', 47.4369, 19.2556)]])
            : Http::response(['elements' => [$this->aerodrome('Budaörsi repülőtér', 47.4469, 18.9744)]]));

        $nearest = app(OverpassMapDataSource::class)->nearest('airport', 47.4979, 19.0402);

        $this->assertSame('Ferihegy', $nearest?->name);
    }

    public function test_falls_back_to_any_registered_aerodrome_when_no_real_airport_is_in_range(): void
    {
        // No real airport near Szeged → tier 1 empty → tier 2 (any registered aerodrome) answers.
        $this->fakeOverpass(fn (string $ql) => $this->isRealAirportTier($ql)
            ? Http::response(['elements' => []])
            : Http::response(['elements' => [$this->aerodrome('Szegedi repülőtér', 46.2470, 20.0910)]]));

        $nearest = app(OverpassMapDataSource::class)->nearest('airport', 46.2530, 20.1414);

        $this->assertSame('Szegedi repülőtér', $nearest?->name);
    }

    public function test_a_tier_outage_does_not_silently_drop_to_the_fallback(): void
    {
        // Tier 1 errors (5xx). A genuine outage must fail the whole lookup (→ stale/void),
        // never quietly serve a lesser tier as if tier 1 were empty.
        $this->fakeOverpass(fn (string $ql) => $this->isRealAirportTier($ql)
            ? Http::response('boom', 500)
            : Http::response(['elements' => [$this->aerodrome('fallback', 46.0, 20.0)]]));

        $nearest = app(OverpassMapDataSource::class)->nearest('airport', 46.2530, 20.1414);

        $this->assertNull($nearest);
        // The fallback (icao) tier must never have been queried after the tier-1 failure.
        Http::assertNotSent(fn ($request) => str_contains((string) ($request->data()['data'] ?? ''), 'icao'));
    }
}
