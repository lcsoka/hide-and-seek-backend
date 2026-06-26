<?php

namespace Tests\Feature;

use App\Enums\GameMode;
use App\Enums\QuestionCategory;
use App\Enums\SessionStatus;
use App\Models\Card;
use App\Models\Question;
use App\Models\Session;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class GameModelsTest extends TestCase
{
    use RefreshDatabase;

    private function makeSession(string $code): Session
    {
        return Session::create([
            'join_code' => $code,
            'game_mode' => 'hide_and_seek',
            'state' => 'lobby',
            'status' => 'open',
            'config' => ['rounds' => 3],
            'state_data' => [],
        ]);
    }

    public function test_uuid_keys_and_relations_wire_up(): void
    {
        $session = $this->makeSession('UUID01');
        $team = $session->teams()->create(['name' => 'Reds']);
        $player = $session->players()->create(['display_name' => 'P', 'team_id' => $team->id]);
        $log = $session->actionLogs()->create(['type' => 'session_created', 'player_id' => $player->id]);

        $this->assertTrue(Str::isUuid($session->id));
        $this->assertTrue(Str::isUuid($team->id));
        $this->assertTrue(Str::isUuid($player->id));
        $this->assertTrue(Str::isUuid($log->id));

        $this->assertSame($session->id, $player->session->id);
        $this->assertSame($team->id, $player->team->id);
        $this->assertTrue($session->players->contains($player));
        $this->assertTrue($session->actionLogs->contains($log));

        $session->update(['host_player_id' => $player->id]);
        $this->assertSame($player->id, $session->fresh()->host->id);
    }

    public function test_action_log_is_append_only(): void
    {
        $session = $this->makeSession('UUID02');
        $log = $session->actionLogs()->create(['type' => 'x']);

        // No updated_at column / timestamp on the append-only audit trail.
        $this->assertNull($log->updated_at);
        $this->assertNotNull($log->created_at);
    }

    public function test_enum_and_json_casts(): void
    {
        $session = $this->makeSession('UUID03')->fresh();
        $this->assertSame(SessionStatus::Open, $session->status);
        $this->assertSame(GameMode::HideAndSeek, $session->game_mode);
        $this->assertIsArray($session->config);

        $question = Question::create([
            'category' => 'radar', 'title' => 'Radar', 'prompt' => 'Within X?',
            'reward_draw' => 2, 'reward_keep' => 1, 'parameters' => ['distances' => ['1 mile']],
        ]);
        $this->assertSame(QuestionCategory::Radar, $question->fresh()->category);
        $this->assertSame(['distances' => ['1 mile']], $question->fresh()->parameters);
    }

    public function test_content_models_are_translatable(): void
    {
        $curse = Card::create([
            'key' => 'test_curse',
            'name' => ['en' => 'Test', 'hu' => 'Teszt'],
            'cost' => ['en' => 'Free', 'hu' => 'Ingyen'],
            'description' => ['en' => 'Effect', 'hu' => 'Hatás'],
        ]);

        $this->assertSame('Teszt', $curse->getTranslation('name', 'hu'));
        $this->assertSame('Test', $curse->getTranslation('name', 'en'));

        app()->setLocale('hu');
        $this->assertSame('Teszt', $curse->fresh()->name);
        app()->setLocale('en');
        $this->assertSame('Test', $curse->fresh()->name);
    }
}
