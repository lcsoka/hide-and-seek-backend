<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
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
        $conn = @fsockopen('127.0.0.1', $port, $errno, $errstr, 1.0);
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

    /** Kick off deploy.sh fully detached so it outlives this web request; the log is tailed by the page. */
    public function deploy(): void
    {
        if (! $this->deployEnabled() || $this->isDeploying()) {
            return;
        }
        Cache::put('deploy:running', now()->timestamp, now()->addMinutes(15));

        $cmd = sprintf(
            'PATH=/usr/local/bin:/usr/bin:/bin setsid nohup bash %s >> %s 2>&1 < /dev/null &',
            escapeshellarg((string) config('deploy.script')),
            escapeshellarg((string) config('deploy.log')),
        );
        Process::fromShellCommandline($cmd, base_path())->run();
    }

    public function deployLog(int $lines = 300): string
    {
        $log = (string) config('deploy.log');
        if (! is_file($log)) {
            return '';
        }
        $all = @file($log, FILE_IGNORE_NEW_LINES) ?: [];

        return implode("\n", array_slice($all, -$lines));
    }
}
