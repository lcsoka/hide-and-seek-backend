<?php

namespace Tests\Feature;

use App\Events\GameEventBroadcast;
use App\Game\Geo\ArrayMapDataSource;
use App\Game\Geo\GeoFeature;
use App\Game\Geo\MapDataSource;
use App\Models\Player;
use App\Models\Question;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class OsmQuestionTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{sessionId: string, hostPlayerId: string, seekerPlayerId: string, host: User, seeker: User} */
    private function setUpSeeking(): array
    {
        $host = User::factory()->create();
        Sanctum::actingAs($host);
        $create = $this->postJson('/api/sessions', ['city' => 'budapest', 'game_size' => 'small', 'config' => ['rounds' => 1]]);
        $sessionId = $create->json('id');
        $hostPlayerId = $create->json('players.0.id');

        $seeker = User::factory()->create();
        Sanctum::actingAs($seeker);
        $seekerPlayerId = $this->postJson("/api/sessions/{$create->json('join_code')}/join", ['display_name' => 'Seeker'])->json('player.id');

        Sanctum::actingAs($host);
        $this->postJson("/api/sessions/{$sessionId}/start");
        $this->postJson("/api/sessions/{$sessionId}/actions", ['type' => 'assign_hider', 'payload' => ['player_id' => $hostPlayerId]]);
        $this->postJson("/api/sessions/{$sessionId}/actions", ['type' => 'confirm_hidden']);

        return compact('sessionId', 'hostPlayerId', 'seekerPlayerId', 'host', 'seeker');
    }

    private function bindMuseums(GeoFeature ...$features): void
    {
        $this->app->instance(MapDataSource::class, new ArrayMapDataSource($features));
    }

    private function museum(string $id, float $lat, float $lng): GeoFeature
    {
        return new GeoFeature($id, 'museum', $lat, $lng);
    }

    private function placeBoth(array $ctx, array $hider, array $seeker): void
    {
        Player::whereKey($ctx['hostPlayerId'])->update(['last_lat' => $hider[0], 'last_lng' => $hider[1]]);
        Player::whereKey($ctx['seekerPlayerId'])->update(['last_lat' => $seeker[0], 'last_lng' => $seeker[1]]);
    }

    private function askAndAnswer(array $ctx, string $category): void
    {
        $question = Question::create([
            'key' => "{$category}.museum", 'category' => $category,
            'title' => ['en' => 'Q'], 'prompt' => ['en' => 'Q'], 'reward_draw' => 3, 'reward_keep' => 1,
        ]);

        Sanctum::actingAs($ctx['seeker']);
        $this->postJson("/api/sessions/{$ctx['sessionId']}/actions", [
            'type' => 'ask_question', 'payload' => ['question_id' => $question->id, 'feature' => 'museum'],
        ])->assertOk();

        Sanctum::actingAs($ctx['host']);
        $this->postJson("/api/sessions/{$ctx['sessionId']}/actions", ['type' => 'answer_question'])->assertOk();
    }

    public function test_matching_is_yes_when_nearest_feature_is_shared(): void
    {
        Event::fake([GameEventBroadcast::class]);
        $ctx = $this->setUpSeeking();
        $this->placeBoth($ctx, [47.50, 19.00], [47.60, 19.20]);
        $this->bindMuseums($this->museum('m/1', 47.55, 19.10)); // single museum -> both nearest = it

        $this->askAndAnswer($ctx, 'matching');

        Event::assertDispatched(GameEventBroadcast::class, fn ($e) => $e->type === 'QuestionAnswered' && ($e->payload['answer'] ?? null) === 'yes');
    }

    public function test_matching_is_no_when_nearest_features_differ(): void
    {
        Event::fake([GameEventBroadcast::class]);
        $ctx = $this->setUpSeeking();
        $this->placeBoth($ctx, [47.50, 19.00], [47.60, 19.20]);
        $this->bindMuseums(
            $this->museum('m/near-hider', 47.50, 19.00),
            $this->museum('m/near-seeker', 47.60, 19.20),
        );

        $this->askAndAnswer($ctx, 'matching');

        Event::assertDispatched(GameEventBroadcast::class, fn ($e) => $e->type === 'QuestionAnswered' && ($e->payload['answer'] ?? null) === 'no');
    }

    public function test_measuring_reports_closer_when_hider_is_nearer_their_feature(): void
    {
        Event::fake([GameEventBroadcast::class]);
        $ctx = $this->setUpSeeking();
        $this->placeBoth($ctx, [47.50, 19.00], [47.60, 19.20]);
        $this->bindMuseums($this->museum('m/1', 47.50, 19.00)); // on the hider, far from the seeker

        $this->askAndAnswer($ctx, 'measuring');

        Event::assertDispatched(GameEventBroadcast::class, fn ($e) => $e->type === 'QuestionAnswered' && ($e->payload['answer'] ?? null) === 'closer');
    }

    public function test_falls_back_to_manual_when_no_map_data(): void
    {
        Event::fake([GameEventBroadcast::class]);
        $ctx = $this->setUpSeeking();
        $this->placeBoth($ctx, [47.50, 19.00], [47.60, 19.20]);
        $this->bindMuseums(); // empty -> nearest() is null -> not auto-evaluable

        $question = Question::create([
            'key' => 'matching.museum', 'category' => 'matching',
            'title' => ['en' => 'Q'], 'prompt' => ['en' => 'Q'], 'reward_draw' => 3, 'reward_keep' => 1,
        ]);
        Sanctum::actingAs($ctx['seeker']);
        $this->postJson("/api/sessions/{$ctx['sessionId']}/actions", ['type' => 'ask_question', 'payload' => ['question_id' => $question->id, 'feature' => 'museum']])->assertOk();
        Sanctum::actingAs($ctx['host']);
        $this->postJson("/api/sessions/{$ctx['sessionId']}/actions", ['type' => 'answer_question', 'payload' => ['answer' => 'manual']])->assertOk();

        Event::assertDispatched(GameEventBroadcast::class, fn ($e) => $e->type === 'QuestionAnswered' && ($e->payload['answer'] ?? null) === 'manual');
    }

    public function test_tentacles_reports_in_range_with_nearest_feature(): void
    {
        Event::fake([GameEventBroadcast::class]);
        $ctx = $this->setUpSeeking();
        $this->placeBoth($ctx, [47.4979, 19.0402], [47.5000, 19.0500]); // ~800 m apart
        $this->bindMuseums($this->museum('m/1', 47.4980, 19.0410)); // near the hider
        $q = Question::create([
            'key' => 'tentacles.museums', 'category' => 'tentacles',
            'title' => ['en' => 'T'], 'prompt' => ['en' => 'T'], 'reward_draw' => 4, 'reward_keep' => 2,
        ]);

        Sanctum::actingAs($ctx['seeker']);
        $this->postJson("/api/sessions/{$ctx['sessionId']}/actions", ['type' => 'ask_question', 'payload' => ['question_id' => $q->id, 'feature' => 'museum', 'radius_m' => 5000]])->assertOk();
        Sanctum::actingAs($ctx['host']);
        $this->postJson("/api/sessions/{$ctx['sessionId']}/actions", ['type' => 'answer_question'])->assertOk();

        Event::assertDispatched(GameEventBroadcast::class, fn ($e) => $e->type === 'QuestionAnswered'
            && ($e->payload['answer'] ?? null) === 'in_range' && ($e->payload['feature_id'] ?? null) === 'm/1');
    }

    public function test_tentacles_reports_out_of_range(): void
    {
        Event::fake([GameEventBroadcast::class]);
        $ctx = $this->setUpSeeking();
        $this->placeBoth($ctx, [47.5316, 21.6273], [47.4979, 19.0402]); // ~200 km apart
        $this->bindMuseums();
        $q = Question::create([
            'key' => 'tentacles.museums', 'category' => 'tentacles',
            'title' => ['en' => 'T'], 'prompt' => ['en' => 'T'], 'reward_draw' => 4, 'reward_keep' => 2,
        ]);

        Sanctum::actingAs($ctx['seeker']);
        $this->postJson("/api/sessions/{$ctx['sessionId']}/actions", ['type' => 'ask_question', 'payload' => ['question_id' => $q->id, 'feature' => 'museum', 'radius_m' => 5000]])->assertOk();
        Sanctum::actingAs($ctx['host']);
        $this->postJson("/api/sessions/{$ctx['sessionId']}/actions", ['type' => 'answer_question'])->assertOk();

        Event::assertDispatched(GameEventBroadcast::class, fn ($e) => $e->type === 'QuestionAnswered' && ($e->payload['answer'] ?? null) === 'out_of_range');
    }

    public function test_thermometer_reports_hotter_when_seeker_moves_closer(): void
    {
        Event::fake([GameEventBroadcast::class]);
        $ctx = $this->setUpSeeking();
        Player::whereKey($ctx['hostPlayerId'])->update(['last_lat' => 47.4979, 'last_lng' => 19.0402]); // hider
        Player::whereKey($ctx['seekerPlayerId'])->update(['last_lat' => 47.6000, 'last_lng' => 19.2000]); // seeker starts far

        $q = Question::create([
            'key' => 'thermometer', 'category' => 'thermometer',
            'title' => ['en' => 'Th'], 'prompt' => ['en' => 'Th'], 'reward_draw' => 2, 'reward_keep' => 1,
        ]);

        Sanctum::actingAs($ctx['seeker']);
        $this->postJson("/api/sessions/{$ctx['sessionId']}/actions", ['type' => 'ask_question', 'payload' => ['question_id' => $q->id]])->assertOk();

        // Seeker travels toward the hider, then the hider answers.
        Player::whereKey($ctx['seekerPlayerId'])->update(['last_lat' => 47.5100, 'last_lng' => 19.0600]);
        Sanctum::actingAs($ctx['host']);
        $this->postJson("/api/sessions/{$ctx['sessionId']}/actions", ['type' => 'answer_question'])->assertOk();

        Event::assertDispatched(GameEventBroadcast::class, fn ($e) => $e->type === 'QuestionAnswered' && ($e->payload['answer'] ?? null) === 'hotter');
    }
}
