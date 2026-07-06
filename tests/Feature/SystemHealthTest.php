<?php

namespace Tests\Feature;

use App\Support\SystemHealth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class SystemHealthTest extends TestCase
{
    use RefreshDatabase;

    private function health(): SystemHealth
    {
        return app(SystemHealth::class);
    }

    private function services(): array
    {
        return collect($this->health()->services())->keyBy('key')->all();
    }

    public function test_services_report_db_and_cache_up(): void
    {
        // Seed the Overpass probe cache so services() doesn't make a live network call.
        Cache::put('health:overpass', ['ok' => true, 'detail' => 'seeded'], 300);
        $services = $this->services();

        $this->assertCount(7, $services);
        foreach ($services as $s) {
            $this->assertArrayHasKey('label', $s);
            $this->assertArrayHasKey('ok', $s);
            $this->assertArrayHasKey('detail', $s);
        }
        $this->assertTrue($services['database']['ok']);
        $this->assertTrue($services['cache']['ok']);
    }

    public function test_overpass_uses_the_cached_probe(): void
    {
        Cache::put('health:overpass', ['ok' => false, 'detail' => 'HTTP 504'], 300);
        $this->assertFalse($this->services()['overpass']['ok']);

        Cache::put('health:overpass', ['ok' => true, 'detail' => 'localhost · 12ms'], 300);
        $this->assertTrue($this->services()['overpass']['ok']);
    }

    public function test_queue_and_scheduler_reflect_heartbeat_freshness(): void
    {
        // Fresh heartbeats → up.
        Cache::put('health:queue', now()->timestamp, 300);
        Cache::put('health:scheduler', now()->timestamp, 300);
        $services = $this->services();
        $this->assertTrue($services['queue']['ok']);
        $this->assertTrue($services['scheduler']['ok']);

        // Stale heartbeat → down.
        Cache::put('health:queue', now()->subMinutes(5)->timestamp, 300);
        $this->assertFalse($this->services()['queue']['ok']);

        // Missing heartbeat → down.
        Cache::forget('health:scheduler');
        $this->assertFalse($this->services()['scheduler']['ok']);
    }

    public function test_version_uses_the_cached_result(): void
    {
        Cache::put('health:version', [
            'current' => 'abc1234', 'remote' => 'def5678',
            'up_to_date' => false, 'available' => true, 'error' => null,
        ], now()->addMinutes(5));

        $v = $this->health()->version();
        $this->assertTrue($v['available']);
        $this->assertSame('abc1234', $v['current']);
    }

    public function test_deploy_logs_are_per_file_newest_first_and_selectable_by_name(): void
    {
        $dir = sys_get_temp_dir().'/hns-deploy-'.uniqid();
        config(['deploy.log_dir' => $dir]);
        mkdir($dir, 0775, true);
        file_put_contents("$dir/deploy-2026-07-06_100000.log", "old deploy\nline2\n");
        file_put_contents("$dir/deploy-2026-07-06_120000.log", "new deploy\nDone.\n");
        touch("$dir/deploy-2026-07-06_100000.log", now()->subHour()->timestamp);
        touch("$dir/deploy-2026-07-06_120000.log", now()->timestamp);

        try {
            $logs = $this->health()->deployLogs();
            $this->assertCount(2, $logs);
            $this->assertSame('deploy-2026-07-06_120000.log', $logs[0]['name']); // newest first

            $this->assertStringContainsString('old deploy', $this->health()->deployLog('deploy-2026-07-06_100000.log'));
            $this->assertStringContainsString('Done.', $this->health()->deployLog(null)); // newest by default
            $this->assertStringContainsString('Done.', $this->health()->deployLog('../../.env')); // traversal → newest
        } finally {
            array_map('unlink', glob("$dir/*") ?: []);
            @rmdir($dir);
        }
    }

    public function test_deploy_is_disabled_by_default(): void
    {
        config(['deploy.enabled' => false]);
        $this->assertFalse($this->health()->deployEnabled());

        $this->health()->deploy(); // must be a no-op
        $this->assertFalse($this->health()->isDeploying());
    }
}
