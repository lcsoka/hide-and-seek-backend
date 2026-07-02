<?php

namespace App\Game\Geo;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Resilient Overpass HTTP. Tries the configured mirrors with status-aware retry (5xx / 429
 * get a backed-off retry; a 4xx fails fast since retrying a bad query won't help), moves a
 * mirror that just failed to the back (a light circuit breaker), and short-circuits during a
 * full outage so we don't make every request wait on two dead mirrors. Returns the decoded
 * JSON (the full `{elements: …}` object) on success, or null if every mirror failed — caching
 * and stale-fallback are the callers' concern.
 */
final class OverpassHttp
{
    private const ATTEMPTS_PER_ENDPOINT = 2;  // one retry per mirror on a transient failure
    private const UNHEALTHY_TTL = 60;         // seconds a failing mirror stays deprioritised
    private const ALL_DOWN_TTL = 20;          // seconds to skip the network after a full failure
    private const BACKOFF_BASE_MS = 150;

    /**
     * @param  int|null  $maxAttempts  per-endpoint attempts; defaults to ATTEMPTS_PER_ENDPOINT.
     *   Pass 1 for latency-sensitive callers (e.g. work already retried at the job level, or a
     *   synchronous request path that must not block on per-endpoint retries).
     * @return array<string, mixed>|null
     */
    public function fetch(string $ql, int $timeoutSeconds, ?int $maxAttempts = null): ?array
    {
        $attempts = max(1, $maxAttempts ?? self::ATTEMPTS_PER_ENDPOINT);

        // During a known full outage, fail fast (callers serve stale / a manual answer) instead
        // of making every request block on two dead mirrors.
        if (Cache::get('overpass:all_down')) {
            return null;
        }

        $userAgent = (string) config('game.overpass.user_agent', 'HideAndSeek/1.0');

        foreach ($this->orderedEndpoints() as $endpoint) {
            for ($attempt = 1; $attempt <= $attempts; $attempt++) {
                try {
                    $response = Http::withHeaders(['User-Agent' => $userAgent])
                        ->asForm()->connectTimeout(5)->timeout($timeoutSeconds)
                        ->post($endpoint, ['data' => $ql]);

                    if ($response->successful()) {
                        Cache::forget($this->healthKey($endpoint));
                        Cache::forget('overpass:all_down');

                        return $response->json() ?? [];
                    }

                    $status = $response->status();
                    Log::warning('Overpass returned an error status', ['endpoint' => $endpoint, 'status' => $status, 'attempt' => $attempt]);
                    // A 4xx other than rate-limit is a bad query; another attempt/mirror won't help.
                    if ($status < 500 && $status !== 429) {
                        return null;
                    }
                } catch (Throwable $e) {
                    Log::warning('Overpass request failed', ['endpoint' => $endpoint, 'error' => $e->getMessage(), 'attempt' => $attempt]);
                }

                // Transient failure (5xx / 429 / timeout): deprioritise this mirror, then back off.
                Cache::put($this->healthKey($endpoint), true, now()->addSeconds(self::UNHEALTHY_TTL));
                if ($attempt < $attempts) {
                    $this->backoff($attempt);
                }
            }
        }

        // Every mirror failed — cool off briefly so we don't hammer a downed API.
        Cache::put('overpass:all_down', true, now()->addSeconds(self::ALL_DOWN_TTL));

        return null;
    }

    /**
     * Configured mirrors with any recently-failing ones moved to the back, so a healthy
     * mirror is tried first (config order otherwise preserved).
     *
     * @return array<int, string>
     */
    private function orderedEndpoints(): array
    {
        $endpoints = array_values(array_filter((array) config('game.overpass.endpoints', [config('game.overpass.endpoint')])));
        $healthy = [];
        $unhealthy = [];
        foreach ($endpoints as $endpoint) {
            Cache::get($this->healthKey($endpoint)) ? ($unhealthy[] = $endpoint) : ($healthy[] = $endpoint);
        }

        return [...$healthy, ...$unhealthy];
    }

    private function healthKey(string $endpoint): string
    {
        return 'overpass:down:'.sha1($endpoint);
    }

    private function backoff(int $attempt): void
    {
        if (app()->runningUnitTests()) {
            return; // keep the suite fast; the retry path is still exercised
        }
        // Exponential base + jitter to avoid synchronised retries across concurrent requests.
        usleep((self::BACKOFF_BASE_MS * (2 ** ($attempt - 1)) + random_int(0, 150)) * 1000);
    }
}
