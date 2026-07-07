<?php

namespace Tests\Feature;

use App\Events\GameEventBroadcast;
use App\Models\Card;
use App\Models\Question;
use Database\Seeders\CardSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use Tests\Support\SeekingScenario;
use Tests\TestCase;

/** Every hider deck card type — curses, time bonuses, and each powerup — and its outcome. */
class CardOutcomesTest extends TestCase
{
    use RefreshDatabase, SeekingScenario;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CardSeeder::class); // the deck is now DB-backed — give it cards to draw
        Event::fake([GameEventBroadcast::class]);
    }

    private function play(array $ctx, array $card, string $type, array $extra = []): void
    {
        $this->giveHiderCard($ctx['sessionId'], $card);
        Sanctum::actingAs($ctx['host']);
        $this->postJson("/api/v1/sessions/{$ctx['sessionId']}/actions", ['type' => $type, 'payload' => ['card_uid' => $card['uid']] + $extra])->assertOk();
    }

    private function state(array $ctx): array
    {
        Sanctum::actingAs($ctx['host']);

        return $this->getJson("/api/v1/sessions/{$ctx['sessionId']}/state")->json();
    }

    public function test_every_official_curse_plays_and_reflects_its_parameters(): void
    {
        $this->seed(CardSeeder::class);
        $ctx = $this->startSeeking();
        $curses = Card::where('type', 'curse')->where('is_active', true)->get();
        $this->assertGreaterThanOrEqual(24, $curses->count());

        foreach ($curses->values() as $i => $curse) {
            $extra = ! empty($curse->effect['hider_photo']) ? ['photo_url' => 'https://example.com/streetview.jpg'] : [];
            if (! empty($curse->effect['hangman'])) {
                $extra['word'] = 'VILLAMOS'; // the Hidden Hangman needs a word from the hider
            }
            $this->play($ctx, ['uid' => "c{$i}", 'type' => 'curse', 'curse_id' => $curse->id], 'play_curse', $extra);

            $active = collect($this->state($ctx)['curses'])->firstWhere('curse_id', $curse->id);
            $this->assertNotNull($active, "curse {$curse->key} should be active after playing");
            $this->assertNotNull($active['name']);

            $params = $curse->effect ?? [];
            $this->assertSame((bool) ($params['requires_proof'] ?? false), $active['requires_proof'], "{$curse->key} requires_proof");
            $this->assertSame($params['dice'] ?? null, $active['dice'], "{$curse->key} dice");
            if (isset($params['duration_s'])) {
                $this->assertNotNull($active['expires_at'], "{$curse->key} should expire");
            } else {
                $this->assertNull($active['expires_at'], "{$curse->key} should not expire");
            }
        }
    }

    public function test_time_bonus_card_adds_its_minutes_to_banked_time(): void
    {
        $ctx = $this->startSeeking();
        $this->giveHiderCard($ctx['sessionId'], ['uid' => 't1', 'type' => 'time_bonus', 'minutes' => 12]);

        $this->assertSame(720, $this->state($ctx)['time_bonus_s']);
    }

    public function test_powerup_veto_discards_the_pending_question(): void
    {
        $ctx = $this->startSeeking();
        $this->place($ctx, [47.50, 19.04], [47.55, 19.10]);
        $q = Question::create(['key' => 'radar.v', 'category' => 'radar', 'title' => ['en' => 'R'], 'prompt' => ['en' => '?'], 'reward_draw' => 1, 'reward_keep' => 1]);
        Sanctum::actingAs($ctx['seeker']);
        $this->postJson("/api/v1/sessions/{$ctx['sessionId']}/actions", ['type' => 'ask_question', 'payload' => ['question_id' => $q->id, 'radius_m' => 5000]])->assertOk();

        Event::fake([GameEventBroadcast::class]);
        $this->play($ctx, ['uid' => 'v', 'type' => 'powerup', 'power' => 'veto'], 'play_powerup');

        $state = $this->state($ctx);
        $this->assertNull($state['pending_question']);
        $this->assertCount(0, $state['questions']);
        // The seeker is told their question was vetoed (rather than waiting on a vanished one).
        Event::assertDispatched(GameEventBroadcast::class, fn ($e) => $e->type === 'QuestionVetoed');
    }

    public function test_overflowing_chalice_grants_an_extra_draw_on_the_next_answer(): void
    {
        $this->seed(CardSeeder::class);
        $chalice = Card::where('key', 'the_overflowing_chalice')->firstOrFail();
        $ctx = $this->startSeeking();
        $this->place($ctx, [47.50, 19.04], [47.55, 19.10]);

        $this->play($ctx, ['uid' => 'ch', 'type' => 'curse', 'curse_id' => $chalice->id], 'play_curse');
        // Self-buff: it doesn't linger as an active seeker curse.
        $this->assertEmpty(array_filter($this->state($ctx)['curses'], fn ($c) => ($c['status'] ?? '') === 'active'));

        // A radar question rewards 1 draw normally; with the chalice it draws 2.
        $q = Question::create(['key' => 'radar.c', 'category' => 'radar', 'title' => ['en' => 'R'], 'prompt' => ['en' => '?'], 'reward_draw' => 1, 'reward_keep' => 1]);
        Sanctum::actingAs($ctx['seeker']);
        $this->postJson("/api/v1/sessions/{$ctx['sessionId']}/actions", ['type' => 'ask_question', 'payload' => ['question_id' => $q->id, 'radius_m' => 5000]])->assertOk();
        Sanctum::actingAs($ctx['host']);
        $this->postJson("/api/v1/sessions/{$ctx['sessionId']}/actions", ['type' => 'answer_question', 'payload' => ['answer' => 'yes']])->assertOk();

        $this->assertCount(2, $this->state($ctx)['pending_draw']['cards']);
    }

    public function test_powerup_duplicate_copies_a_chosen_card(): void
    {
        $ctx = $this->startSeeking();
        $this->giveHiderCard($ctx['sessionId'], ['uid' => 'a', 'type' => 'time_bonus', 'minutes' => 5]);
        $this->play($ctx, ['uid' => 'd', 'type' => 'powerup', 'power' => 'duplicate'], 'play_powerup', ['target_uid' => 'a']);

        $hand = $this->state($ctx)['hand'];
        $this->assertCount(2, $hand);
        $this->assertSame(['time_bonus', 'time_bonus'], array_map(fn ($c) => $c['type'], $hand));
    }

    public function test_powerup_randomize_replaces_the_hand_with_fresh_cards(): void
    {
        $ctx = $this->startSeeking();
        $this->giveHiderCard($ctx['sessionId'], ['uid' => 'a', 'type' => 'time_bonus', 'minutes' => 5]);
        $this->giveHiderCard($ctx['sessionId'], ['uid' => 'b', 'type' => 'time_bonus', 'minutes' => 8]);
        $this->play($ctx, ['uid' => 'r', 'type' => 'powerup', 'power' => 'randomize'], 'play_powerup');

        $uids = array_map(fn ($c) => $c['uid'], $this->state($ctx)['hand']);
        $this->assertCount(2, $uids); // the two non-randomize cards, redrawn
        $this->assertNotContains('a', $uids);
        $this->assertNotContains('b', $uids);
    }

    public function test_powerup_discard_1_draw_2_opens_a_two_card_draw(): void
    {
        $this->assertDrawPowerup('discard_1_draw_2', 2);
    }

    public function test_powerup_discard_2_draw_3_opens_a_three_card_draw(): void
    {
        $this->assertDrawPowerup('discard_2_draw_3', 3);
    }

    public function test_powerup_draw_1_expand_1_opens_a_one_card_draw(): void
    {
        $this->assertDrawPowerup('draw_1_expand_1', 1);
    }

    private function assertDrawPowerup(string $power, int $expected): void
    {
        $ctx = $this->startSeeking();
        $this->play($ctx, ['uid' => 'p', 'type' => 'powerup', 'power' => $power], 'play_powerup');

        $draw = $this->state($ctx)['pending_draw'];
        $this->assertNotNull($draw, "{$power} should open a draw");
        $this->assertCount($expected, $draw['cards']);
    }

    public function test_powerup_move_is_consumed_and_announced(): void
    {
        $ctx = $this->startSeeking();
        $this->play($ctx, ['uid' => 'm', 'type' => 'powerup', 'power' => 'move'], 'play_powerup');

        $this->assertCount(0, $this->state($ctx)['hand']);
        Event::assertDispatched(GameEventBroadcast::class, fn ($e) => $e->type === 'PowerupPlayed' && ($e->payload['power'] ?? null) === 'move');
    }
}
