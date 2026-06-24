<?php

namespace Tests\Unit;

use App\Enums\GameSize;
use App\Game\GameModeRegistry;
use App\Game\Modes\HideAndSeek\HideAndSeekMode;
use Tests\TestCase;

class HideAndSeekModeTest extends TestCase
{
    public function test_default_config_scales_with_size(): void
    {
        $mode = new HideAndSeekMode;

        $small = $mode->defaultConfig(GameSize::Small);
        $large = $mode->defaultConfig(GameSize::Large);

        $this->assertSame('small', $small['game_size']);
        $this->assertSame(900, $small['hiding_time_limit_s']);
        $this->assertEquals(3.0, $small['play_radius_km']);

        $this->assertSame('large', $large['game_size']);
        $this->assertSame(3600, $large['hiding_time_limit_s']);
        $this->assertEquals(100.0, $large['play_radius_km']);

        $this->assertSame('lobby', $mode->initialState());
        $this->assertSame(0, $mode->initialStateData()['round']);
    }

    public function test_registry_resolves_registered_modes(): void
    {
        $registry = new GameModeRegistry;

        $this->assertTrue($registry->has('hide_and_seek'));
        $this->assertFalse($registry->has('nonexistent'));
        $this->assertInstanceOf(HideAndSeekMode::class, $registry->make('hide_and_seek'));
    }
}
