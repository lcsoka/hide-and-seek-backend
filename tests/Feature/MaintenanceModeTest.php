<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Tests\TestCase;

class MaintenanceModeTest extends TestCase
{
    public function test_livewire_updates_bypass_maintenance_so_the_admin_deploy_log_keeps_polling(): void
    {
        // Livewire v3's update route lives under a hashed prefix — the old 'livewire/*' never matched
        // it (so the deploy-log poll 503'd during a deploy); 'livewire-*' does.
        $this->assertFalse(Str::is('livewire/*', 'livewire-808f758e/update'));
        $this->assertTrue(Str::is('livewire-*', 'livewire-808f758e/update'));

        Artisan::call('down');
        try {
            // Excepted → not blocked by maintenance (no such route in test env → 404, crucially NOT 503).
            $this->assertNotSame(503, $this->get('/livewire-808f758e/update')->getStatusCode());
            // The public API is NOT excepted → still 503 during maintenance.
            $this->assertSame(503, $this->get('/api/v1/questions')->getStatusCode());
        } finally {
            Artisan::call('up');
        }
    }
}
