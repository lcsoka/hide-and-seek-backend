<?php

namespace Tests\Feature;

use App\Game\Geo\OverpassMapDataSource;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class StationDedupTest extends TestCase
{
    /** @return array<int, \App\Game\Geo\GeoFeature> */
    private function primeAndFetch(string $type): array
    {
        $lat = 47.5;
        $lng = 19.05;
        $radius = 1000;

        // Two directional platforms of "Deák tér" ~33 m apart, plus a distinct "Astoria".
        $elements = [
            ['type' => 'node', 'id' => 1, 'lat' => 47.5000, 'lon' => 19.0500, 'tags' => ['name' => 'Deák tér']],
            ['type' => 'node', 'id' => 2, 'lat' => 47.5003, 'lon' => 19.0500, 'tags' => ['name' => 'Deák tér']],
            ['type' => 'node', 'id' => 3, 'lat' => 47.5020, 'lon' => 19.0500, 'tags' => ['name' => 'Astoria']],
        ];
        Cache::put(sprintf('overpass:%s:%d:%.3f:%.3f', $type, $radius, $lat, $lng), $elements, now()->addHour());

        return app(OverpassMapDataSource::class)->within($type, $lat, $lng, $radius);
    }

    public function test_directional_station_platforms_collapse_to_one(): void
    {
        $features = $this->primeAndFetch('tram_stop');
        $names = array_map(fn ($f) => $f->name, $features);

        // The two "Deák tér" platforms become one station; "Astoria" stays separate.
        $this->assertCount(2, $features);
        $this->assertSame(1, count(array_filter($names, fn ($n) => $n === 'Deák tér')));
        $this->assertContains('Astoria', $names);
    }

    public function test_pois_with_the_same_name_are_not_collapsed(): void
    {
        // Museums are POIs, not stations — two same-named nearby ones must stay distinct.
        $this->assertCount(3, $this->primeAndFetch('museum'));
    }
}
