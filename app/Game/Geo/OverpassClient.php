<?php

namespace App\Game\Geo;

use Illuminate\Support\Facades\Cache;

/**
 * Server-side Overpass executor: runs raw Overpass QL via the resilient OverpassHttp layer
 * (retry / mirror fallback) and caches successful responses (6h, keyed by the query) so
 * repeated client queries hit OpenStreetMap at most once. The web app proxies ALL of its
 * Overpass traffic through this (via GeoController) instead of calling the public API
 * directly, so there is a single shared cache and no per-client rate-limiting.
 *
 * On a total failure it serves the last-known-good response (kept for STALE_TTL_DAYS) so an
 * Overpass outage degrades to slightly-stale map data rather than a broken map.
 */
final class OverpassClient
{
    private const TTL_HOURS = 6;
    private const STALE_TTL_DAYS = 7;
    private const TIMEOUT = 25;

    public function __construct(private readonly OverpassHttp $http) {}

    /**
     * Run an Overpass QL query and return the decoded JSON (the full `{elements: …}`
     * object, ready for osmtogeojson), or null if every mirror failed and no stale copy exists.
     *
     * @return array<string, mixed>|null
     */
    public function run(string $ql): ?array
    {
        $freshKey = 'overpass_ql:'.sha1($ql);
        $staleKey = 'overpass_ql:stale:'.sha1($ql);

        $cached = Cache::get($freshKey);
        if ($cached !== null) {
            return $cached;
        }

        $result = $this->http->fetch($ql, self::TIMEOUT);
        if ($result !== null) {
            Cache::put($freshKey, $result, now()->addHours(self::TTL_HOURS));
            Cache::put($staleKey, $result, now()->addDays(self::STALE_TTL_DAYS));

            return $result;
        }

        // Overpass unreachable — serve the last-known-good response if we have one.
        return Cache::get($staleKey);
    }
}
