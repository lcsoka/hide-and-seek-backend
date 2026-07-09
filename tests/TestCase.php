<?php

namespace Tests;

use App\Models\City;
use Database\Seeders\CitySeeder;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Keep tests deterministic: queued jobs (timers, queued broadcasts) don't
        // run synchronously. Timer/job behaviour is exercised explicitly where needed.
        Queue::fake();

        // Playable cities moved to the DB (create validates `city` against `cities`), so every
        // feature test that creates a session needs them seeded. Guarded so non-DB tests skip it.
        if (Schema::hasTable('cities') && City::count() === 0) {
            (new CitySeeder)->run();
        }
    }
}
