<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Queue;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Keep tests deterministic: queued jobs (timers, queued broadcasts) don't
        // run synchronously. Timer/job behaviour is exercised explicitly where needed.
        Queue::fake();
    }
}
