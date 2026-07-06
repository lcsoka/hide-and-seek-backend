<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

/**
 * Health checks + version/deploy helpers for the admin "System" page. Every check is defensive:
 * anything unreachable is reported as down rather than throwing. Queue + scheduler liveness use
 * heartbeats written by the scheduler (see routes/console.php + App\Jobs\QueueHeartbeat).
 */
class SystemHealth
{
    private const HEARTBEAT_STALE_S = 120;

    /** @return array<int, array{key: string, label: string, ok: bool, detail: string}> */
    public function services(): array
    {
        return [
            $this->database(),
            $this->cache(),
            $this->redis(),
            $this->reverb(),
            $this->queue(),
            $this->scheduler(),
            $this->overpass(),
        ];
    }

    /** @return array{key: string, label: string, ok: bool, detail: string} */
    private function svc(string $key, string $label, bool $ok, string $detail): array
    {
        return compact('key', 'label', 'ok', 'detail');
    }

    private function database(): array
    {
        try {
            $t = microtime(true);
            DB::connection()->select('select 1');

            return $this->svc('database', 'Database', true, config('database.default').' · '.((int) round((microtime(true) - $t) * 1000)).'ms');
        } catch (\Throwable $e) {
            return $this->svc('database', 'Database', false, $this->err($e));
        }
    }

    private function cache(): array
    {
        try {
            $token = (string) now()->timestamp.'-'.Str::random(6);
            Cache::put('health:cache', $token, 10);

            return $this->svc('cache', 'Cache', Cache::get('health:cache') === $token, (string) config('cache.default'));
        } catch (\Throwable $e) {
            return $this->svc('cache', 'Cache', false, $this->err($e));
        }
    }

    private function redis(): array
    {
        if (! in_array('redis', [config('cache.default'), config('queue.default'), config('session.driver')], true)) {
            return $this->svc('redis', 'Redis', true, 'not in use');
        }
        try {
            $pong = Redis::connection()->ping();

            return $this->svc('redis', 'Redis', $pong === true || in_array($pong, ['PONG', '+PONG'], true), 'PONG');
        } catch (\Throwable $e) {
            return $this->svc('redis', 'Redis', false, $this->err($e));
        }
    }

    private function reverb(): array
    {
        $port = (int) config('deploy.reverb_port', 8080);
        // Short timeout: on localhost a live socket answers in <10ms, and a fast poll during a
        // deploy shouldn't stall a full second each time Reverb is momentarily down.
        $conn = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.3);
        if ($conn !== false) {
            fclose($conn);

            return $this->svc('reverb', 'Reverb (WebSocket)', true, 'listening on :'.$port);
        }

        return $this->svc('reverb', 'Reverb (WebSocket)', false, 'not listening on :'.$port);
    }

    private function queue(): array
    {
        [$ok, $detail] = $this->heartbeat('health:queue', 'worker alive', 'no worker heartbeat');

        return $this->svc('queue', 'Queue workers', $ok, config('queue.default').' · '.$detail);
    }

    private function scheduler(): array
    {
        [$ok, $detail] = $this->heartbeat('health:scheduler', 'ran', 'no scheduler heartbeat');

        return $this->svc('scheduler', 'Scheduler (cron)', $ok, $detail);
    }

    /**
     * Live probe of the primary Overpass endpoint (a tiny count query), cached 3 min so the polling
     * dashboard doesn't hammer it. Answers the "is my (self-hosted) Overpass reachable?" question.
     */
    private function overpass(): array
    {
        $r = Cache::remember('health:overpass', now()->addMinutes(3), function () {
            $endpoint = (string) config('game.overpass.endpoint');
            try {
                $t = microtime(true);
                $res = Http::timeout(8)
                    ->withHeaders(['User-Agent' => (string) config('game.overpass.user_agent', 'HideAndSeek/1.0')])
                    ->get($endpoint, ['data' => '[out:json][timeout:5];node(47.49,19.03,47.50,19.04);out count;']);
                $ms = (int) round((microtime(true) - $t) * 1000);
                $ok = $res->successful() && str_contains($res->body(), 'elements');

                return ['ok' => $ok, 'detail' => $ok
                    ? (parse_url($endpoint, PHP_URL_HOST) ?: 'endpoint').' · '.$ms.'ms'
                    : 'HTTP '.$res->status()];
            } catch (\Throwable $e) {
                return ['ok' => false, 'detail' => $this->err($e)];
            }
        });

        return $this->svc('overpass', 'Overpass (OSM)', (bool) $r['ok'], (string) $r['detail']);
    }

    /** @return array{0: bool, 1: string} */
    private function heartbeat(string $key, string $aliveWord, string $missing): array
    {
        $stamp = (int) Cache::get($key, 0);
        if ($stamp === 0) {
            return [false, $missing];
        }
        $age = now()->timestamp - $stamp;

        return [$age < self::HEARTBEAT_STALE_S, ($age < self::HEARTBEAT_STALE_S ? $aliveWord.' ' : 'stale — ').$age.'s ago'];
    }

    private function err(\Throwable $e): string
    {
        return Str::limit($e->getMessage(), 70);
    }

    // --- version ------------------------------------------------------------

    /** @return array{current: ?string, remote: ?string, up_to_date: bool, available: bool, error: ?string} */
    public function version(): array
    {
        return Cache::remember('health:version', now()->addMinutes(5), function () {
            $current = $this->git(['rev-parse', 'HEAD']);
            $lsRemote = $this->git(['ls-remote', (string) config('deploy.git_remote', 'origin'), (string) config('deploy.git_branch', 'main')]);
            $remote = ($lsRemote !== null && preg_match('/^([0-9a-f]{40})/', $lsRemote, $m)) ? $m[1] : null;

            $upToDate = $current !== null && $current === $remote;

            return [
                'current' => $current ? substr($current, 0, 7) : null,
                'remote' => $remote ? substr($remote, 0, 7) : null,
                'up_to_date' => $upToDate,
                'available' => $current !== null && $remote !== null && ! $upToDate,
                'error' => ($current === null || $remote === null) ? 'git/remote unavailable' : null,
            ];
        });
    }

    public function refreshVersion(): void
    {
        Cache::forget('health:version');
    }

    private function git(array $args): ?string
    {
        try {
            $p = new Process(array_merge(['git'], $args), base_path());
            $p->setTimeout(15);
            $p->run();

            return $p->isSuccessful() ? trim($p->getOutput()) : null;
        } catch (\Throwable) {
            return null;
        }
    }

    // --- deploy -------------------------------------------------------------

    public function deployEnabled(): bool
    {
        return (bool) config('deploy.enabled') && is_file((string) config('deploy.script'));
    }

    public function isDeploying(): bool
    {
        return (bool) Cache::get('deploy:running');
    }

    /** Kick off deploy.sh fully detached so it outlives this web request, logging to its OWN file. */
    public function deploy(): void
    {
        if (! $this->deployEnabled() || $this->isDeploying()) {
            return;
        }
        Cache::put('deploy:running', now()->timestamp, now()->addMinutes(15));

        $dir = (string) config('deploy.log_dir');
        if (! is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $logFile = $dir.'/deploy-'.now()->format('Y-m-d_His').'.log';
        @touch($logFile); // exists immediately so the page shows THIS deploy from the first poll

        $cmd = sprintf(
            'PATH=/usr/local/bin:/usr/bin:/bin setsid nohup bash %s >> %s 2>&1 < /dev/null &',
            escapeshellarg((string) config('deploy.script')),
            escapeshellarg($logFile),
        );
        Process::fromShellCommandline($cmd, base_path())->run();
    }

    /**
     * Past deploys, newest first: [{name, at, label}]. `name` is the log file's basename.
     *
     * @return array<int, array{name: string, at: int, label: string}>
     */
    public function deployLogs(): array
    {
        $files = glob((string) config('deploy.log_dir').'/deploy-*.log') ?: [];
        $items = array_map(fn (string $f) => [
            'name' => basename($f),
            'at' => (int) @filemtime($f),
            'label' => date('Y-m-d H:i:s', (int) @filemtime($f)),
        ], $files);
        usort($items, fn ($a, $b) => $b['at'] <=> $a['at']);

        return $items;
    }

    /** The tail of one deploy's log (by basename), or the newest deploy when $name is null/unknown. */
    public function deployLog(?string $name = null, int $lines = 800): string
    {
        $dir = (string) config('deploy.log_dir');
        $safe = $name !== null ? basename($name) : null;
        $file = ($safe !== null && str_starts_with($safe, 'deploy-') && str_ends_with($safe, '.log') && is_file($dir.'/'.$safe))
            ? $dir.'/'.$safe
            : ($this->deployLogs()[0]['name'] ?? null);

        if ($file === null) {
            return '';
        }
        $path = str_contains($file, '/') ? $file : $dir.'/'.$file;
        $all = @file($path, FILE_IGNORE_NEW_LINES) ?: [];

        return implode("\n", array_slice($all, -$lines));
    }
}
