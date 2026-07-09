<?php

namespace Tests\Support;

use App\Game\Geo\ArrayMapDataSource;
use App\Game\Geo\GeoFeature;
use App\Game\Geo\MapDataSource;
use App\Models\Player;
use App\Models\Question;
use App\Models\Session;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

/**
 * Shared scenario helpers for gameplay feature tests: spin a session up to the
 * seeking phase (host = hider, a second player = seeker), place players, bind map
 * data, ask/answer questions, and hand the hider cards.
 */
trait SeekingScenario
{
    /** @return array{sessionId: string, hiderId: string, seekerId: string, host: User, seeker: User} */
    protected function startSeeking(): array
    {
        $host = User::factory()->create();
        Sanctum::actingAs($host);
        $create = $this->postJson('/api/v1/sessions', ['city' => 'budapest', 'config' => ['rounds' => 1]]);
        $sessionId = $create->json('id');
        $hiderId = $create->json('players.0.id');

        // The play size is now tied to the city (Budapest = medium), but these gameplay scenarios
        // were written around a SMALL game — re-apply the small-size fields before play begins.
        $this->forceSize($sessionId, \App\Enums\GameSize::Small);

        $seeker = User::factory()->create();
        Sanctum::actingAs($seeker);
        $seekerId = $this->postJson("/api/v1/sessions/{$create->json('join_code')}/join", ['display_name' => 'Seeker'])->json('player.id');

        Sanctum::actingAs($host);
        $this->postJson("/api/v1/sessions/{$sessionId}/start");
        $this->postJson("/api/v1/sessions/{$sessionId}/actions", ['type' => 'assign_hider', 'payload' => ['player_id' => $hiderId]]);
        $this->postJson("/api/v1/sessions/{$sessionId}/actions", ['type' => 'confirm_hidden']);

        return compact('sessionId', 'hiderId', 'seekerId', 'host', 'seeker');
    }

    /** Re-apply a specific play size's config to a session (the size is otherwise city-tied). */
    protected function forceSize(string $sessionId, \App\Enums\GameSize $size): void
    {
        $session = Session::find($sessionId);
        $session->update(['config' => array_merge($session->config, [
            'game_size' => $size->value,
            'play_radius_km' => $size->playRadiusKm(),
            'hiding_time_limit_s' => $size->hidingTimeLimitSeconds(),
            'hiding_zone_radius_m' => $size->hidingZoneRadiusMeters(),
            'time_bonus_s' => $size->timeBonusSeconds(),
        ])]);
    }

    /** @param array{0: float, 1: float} $hider @param array{0: float, 1: float} $seeker */
    protected function place(array $ctx, array $hider, array $seeker): void
    {
        Player::whereKey($ctx['hiderId'])->update(['last_lat' => $hider[0], 'last_lng' => $hider[1]]);
        Player::whereKey($ctx['seekerId'])->update(['last_lat' => $seeker[0], 'last_lng' => $seeker[1]]);
    }

    protected function bindFeatures(GeoFeature ...$features): void
    {
        $this->app->instance(MapDataSource::class, new ArrayMapDataSource($features));
    }

    protected function feature(string $id, string $type, float $lat, float $lng, ?string $name = null): GeoFeature
    {
        return new GeoFeature($id, $type, $lat, $lng, $name);
    }

    /** Create + ask a question, then have the hider answer it. */
    protected function askAndAnswer(array $ctx, string $category, array $payload = [], ?array $parameters = null): void
    {
        $question = Question::create([
            'key' => "{$category}.".uniqid(), 'category' => $category,
            'title' => ['en' => ucfirst($category)], 'prompt' => ['en' => 'Q'],
            'reward_draw' => 1, 'reward_keep' => 1, 'parameters' => $parameters,
        ]);

        Sanctum::actingAs($ctx['seeker']);
        $this->postJson("/api/v1/sessions/{$ctx['sessionId']}/actions", ['type' => 'ask_question', 'payload' => ['question_id' => $question->id] + $payload])->assertOk();

        Sanctum::actingAs($ctx['host']);
        $this->postJson("/api/v1/sessions/{$ctx['sessionId']}/actions", ['type' => 'answer_question', 'payload' => $payload])->assertOk();
    }

    /** The most recent answered question's answer payload (as the seeker sees it). */
    protected function lastAnswer(array $ctx): ?array
    {
        Sanctum::actingAs($ctx['seeker']);
        $questions = $this->getJson("/api/v1/sessions/{$ctx['sessionId']}/state")->json('questions');

        return $questions ? end($questions)['answer'] : null;
    }

    protected function giveHiderCard(string $sessionId, array $card): void
    {
        $session = Session::find($sessionId);
        $data = $session->state_data;
        $data['hand'] = array_merge($data['hand'] ?? [], [$card]);
        $session->update(['state_data' => $data]);
    }

    /** The catch handshake: the seeker claims they found the hider, then the hider confirms. */
    protected function catchHider(array $ctx): void
    {
        Sanctum::actingAs($ctx['seeker']);
        $this->postJson("/api/v1/sessions/{$ctx['sessionId']}/actions", ['type' => 'claim_found'])->assertOk();
        Sanctum::actingAs($ctx['host']);
        $this->postJson("/api/v1/sessions/{$ctx['sessionId']}/actions", ['type' => 'confirm_caught'])->assertOk();
    }
}
