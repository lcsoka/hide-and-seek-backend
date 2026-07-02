<?php

namespace App\Game\Geo;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Server-side Overpass executor: runs raw Overpass QL across the configured mirrors with
 * bounded timeouts, and caches successful responses (6h, keyed by the query) so repeated
 * client queries hit OpenStreetMap at most once. The web app proxies ALL of its Overpass
 * traffic through this (via GeoController) instead of calling the public API directly, so
 * there is a single shared cache and no per-client rate-limiting.
 */
final class OverpassClient
{
    private const TTL_HOURS = 6;

    /**
     * Run an Overpass QL query and return the decoded JSON (the full `{elements: …}`
     * object, ready for osmtogeojson), or null if every mirror failed.
     *
     * @return array<string, mixed>|null
     */
    public function run(string $ql): ?array
    {
        $cacheKey = 'overpass_ql:'.sha1($ql);

        $cached = Cache::get($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        // Only successful responses are cached; a failure returns null and retries next
        // time rather than poisoning the cache with an empty result for hours.
        $result = $this->fetch($ql);
        if ($result !== null) {
            Cache::put($cacheKey, $result, now()->addHours(self::TTL_HOURS));
        }

        return $result;
    }

    /** @return array<string, mixed>|null */
    private function fetch(string $ql): ?array
    {
        $endpoints = (array) config('game.overpass.endpoints', [config('game.overpass.endpoint')]);
        $userAgent = (string) config('game.overpass.user_agent', 'HideAndSeek/1.0');

        foreach ($endpoints as $endpoint) {
            try {
                $response = Http::withHeaders(['User-Agent' => $userAgent])
                    ->asForm()->connectTimeout(5)->timeout(25)->post($endpoint, ['data' => $ql]);

                if ($response->successful()) {
                    return $response->json() ?? [];
                }
            } catch (Throwable) {
                // try the next mirror
            }
        }

        return null;
    }
}
