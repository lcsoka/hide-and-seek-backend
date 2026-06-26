<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SimulateGamesTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_game_simulator_plays_clean_games(): void
    {
        $this->artisan('game:simulate', ['--games' => 3, '--rounds' => 2, '--seekers' => 2, '--seed' => 1])
            ->expectsOutputToContain('Completed 3/3 games cleanly.')
            ->expectsOutputToContain('No gameplay anomalies found.')
            ->assertSuccessful();
    }
}
