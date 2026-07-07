<?php

namespace App\Game\Geo;

use App\Game\Support\Geo;
use Illuminate\Support\Facades\Cache;

/**
 * Live backend over the public OpenStreetMap Overpass API (via the resilient OverpassHttp
 * layer). Results are cached by coarse location; on a total failure the last-known-good
 * result is served (stale fallback), and only if there's none does the question fall back to
 * a manual hider answer — it never blocks the game. A local PostGIS backend can later replace
 * this behind the same interface.
 */
final class OverpassMapDataSource implements MapDataSource
{
    private const TIMEOUT = 10;
    private const STALE_TTL_DAYS = 7;

    public function __construct(private readonly OverpassHttp $http) {}

    public function nearest(string $type, float $lat, float $lng): ?GeoFeature
    {
        $radius = (float) config('game.overpass.search_radius_m', 50000);
        $nearest = null;
        $nearestDistance = INF;

        foreach ($this->within($type, $lat, $lng, $radius) as $feature) {
            $distance = Geo::distanceMeters($lat, $lng, $feature->lat, $feature->lng);
            if ($distance < $nearestDistance) {
                $nearestDistance = $distance;
                $nearest = $feature;
            }
        }

        return $nearest;
    }

    public function within(string $type, float $lat, float $lng, float $radiusM): array
    {
        $tiers = $this->tiersFor(config("game.overpass.features.{$type}"));
        if ($tiers === []) {
            return [];
        }

        $radius = (int) $radiusM;
        $cacheKey = sprintf('overpass:%s:%d:%.3f:%.3f', $type, $radius, $lat, $lng);
        $staleKey = "stale:{$cacheKey}";

        $elements = Cache::get($cacheKey);
        if ($elements === null) {
            $elements = $this->fetchTiered($tiers, $radius, $lat, $lng);
            if ($elements !== null) {
                // Cache the success (fresh 6h + a longer-lived stale copy for outages).
                Cache::put($cacheKey, $elements, now()->addHours(6));
                Cache::put($staleKey, $elements, now()->addDays(self::STALE_TTL_DAYS));
            } else {
                // Overpass unreachable — use the last-known-good result if we have one.
                $elements = Cache::get($staleKey);
            }
        }
        $elements ??= [];

        $features = [];
        foreach ($elements as $element) {
            $featureLat = $element['lat'] ?? ($element['center']['lat'] ?? null);
            $featureLng = $element['lon'] ?? ($element['center']['lon'] ?? null);
            if ($featureLat === null || $featureLng === null) {
                continue;
            }

            $features[] = new GeoFeature(
                id: ($element['type'] ?? 'node').'/'.($element['id'] ?? ''),
                type: $type,
                lat: (float) $featureLat,
                lng: (float) $featureLng,
                name: $element['tags']['name'] ?? null,
            );
        }

        return $this->collapseDirectionalStops($type, $features, $lat, $lng);
    }

    /**
     * A transit stop is mapped in OSM as one platform node per direction of travel: same name, a
     * few tens of metres apart. For station feature types, collapse same-named entities within
     * `station_dedup_m` into ONE — keeping the platform closest to the query point — so a single
     * stop counts once for the "nearest station" rule and matching questions. POIs are untouched.
     *
     * @param  array<int, GeoFeature>  $features
     * @return array<int, GeoFeature>
     */
    private function collapseDirectionalStops(string $type, array $features, float $lat, float $lng): array
    {
        if (! in_array($type, (array) config('game.overpass.station_types', []), true)) {
            return $features;
        }
        $threshold = (float) config('game.overpass.station_dedup_m', 90);

        // Nearest to the query first, so the kept representative is the closest platform.
        usort($features, fn (GeoFeature $a, GeoFeature $b) => Geo::distanceMeters($lat, $lng, $a->lat, $a->lng) <=> Geo::distanceMeters($lat, $lng, $b->lat, $b->lng));

        $kept = [];
        foreach ($features as $feature) {
            if ($feature->name === null) {
                $kept[] = $feature; // nameless: can't tell twins apart, keep as-is
                continue;
            }
            foreach ($kept as $existing) {
                if ($existing->name === $feature->name
                    && Geo::distanceMeters($feature->lat, $feature->lng, $existing->lat, $existing->lng) <= $threshold) {
                    continue 2; // a twin platform is already kept
                }
            }
            $kept[] = $feature;
        }

        return $kept;
    }

    /**
     * Query Overpass (via the resilient layer). Returns the elements on success (possibly an
     * empty array — a valid "no features here"), or null if every mirror failed so the caller
     * can distinguish a genuine empty result from an outage and fall back to stale/manual.
     *
     * @return array<int, array<string, mixed>>|null
     */
    /**
     * A feature's config value is either "key=value" (built into `["key"="value"]`) or a raw
     * Overpass filter fragment starting with `[` (e.g. `[aeroway=aerodrome][icao]` to require a
     * real, registered airport instead of any grass strip). Returns null for an unusable value.
     */
    private function filterFor(mixed $tag): ?string
    {
        if (! is_string($tag)) {
            return null;
        }
        if (str_starts_with($tag, '[')) {
            return $tag;
        }
        if (! str_contains($tag, '=')) {
            return null;
        }
        [$key, $value] = explode('=', $tag, 2);

        return "[\"{$key}\"=\"{$value}\"]";
    }

    /**
     * Normalise a feature's config into an ordered list of tiers, each tier a list of Overpass
     * filter fragments. A plain string (or a compound `[...]` filter) is one tier of one filter;
     * a list of lists is an explicit priority ladder (e.g. airport: real airports, then any
     * registered aerodrome). Unusable entries are dropped.
     *
     * @return array<int, array<int, string>>
     */
    private function tiersFor(mixed $config): array
    {
        if ($config === null) {
            return [];
        }
        // A single filter (string) → one tier, one filter.
        if (! is_array($config)) {
            $filter = $this->filterFor($config);

            return $filter === null ? [] : [[$filter]];
        }

        $tiers = [];
        foreach ($config as $tier) {
            $filters = [];
            foreach ((array) $tier as $tag) {
                if (($filter = $this->filterFor($tag)) !== null) {
                    $filters[] = $filter;
                }
            }
            if ($filters !== []) {
                $tiers[] = $filters;
            }
        }

        return $tiers;
    }

    /**
     * Query the tiers in priority order, returning the first tier that has any feature within
     * range. Returns null only on a genuine Overpass outage (so the caller can fall back to a
     * stale cache) — an empty array means every tier was genuinely empty here.
     *
     * @param  array<int, array<int, string>>  $tiers
     * @return array<int, array<string, mixed>>|null
     */
    private function fetchTiered(array $tiers, int $radius, float $lat, float $lng): ?array
    {
        foreach ($tiers as $filters) {
            $elements = $this->fetchUnion($filters, $radius, $lat, $lng);
            if ($elements === null) {
                return null; // outage — don't silently drop to a lower tier
            }
            if ($elements !== []) {
                return $elements; // first non-empty tier wins
            }
        }

        return [];
    }

    /**
     * One Overpass query unioning every filter in a tier (node/way/relation each). Returns the
     * elements, or null if the mirror failed so tiering can distinguish empty from an outage.
     *
     * @param  array<int, string>  $filters
     * @return array<int, array<string, mixed>>|null
     */
    private function fetchUnion(array $filters, int $radius, float $lat, float $lng): ?array
    {
        $clauses = '';
        foreach ($filters as $filter) {
            $clauses .= "node{$filter}(around:{$radius},{$lat},{$lng});"
                ."way{$filter}(around:{$radius},{$lat},{$lng});"
                ."relation{$filter}(around:{$radius},{$lat},{$lng});";
        }
        if ($clauses === '') {
            return [];
        }
        $ql = "[out:json][timeout:15];({$clauses});out center;";

        // One attempt per mirror (no per-endpoint retry): question truth runs in ComputeQuestionTruth
        // which already retries with backoff at the job level, and the synchronous callers (hiding-zone
        // confirm, inline answer fallback) must not block on retries and risk a gateway timeout.
        $result = $this->http->fetch($ql, self::TIMEOUT, maxAttempts: 1);

        return $result === null ? null : ($result['elements'] ?? []);
    }
}
