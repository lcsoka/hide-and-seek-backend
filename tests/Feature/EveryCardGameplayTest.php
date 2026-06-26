<?php

namespace Tests\Feature;

use App\Models\Card;
use App\Models\Question;
use App\Models\Session;
use Database\Seeders\CardSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\SeekingScenario;
use Tests\TestCase;

/**
 * Plays EVERY seeded deck card in a real seeking game and asserts its consequence, so
 * no card type or curse effect can silently break. Also checks cards localize to HU/EN.
 */
class EveryCardGameplayTest extends TestCase
{
    use RefreshDatabase, SeekingScenario;

    /** A fresh seeking game with the two players placed ~8 km apart. */
    private function seek(): array
    {
        $ctx = $this->startSeeking();
        $this->place($ctx, [47.50, 19.04], [47.55, 19.10]);

        return $ctx;
    }

    private function play(array $ctx, string $type, array $payload): void
    {
        Sanctum::actingAs($ctx['host']);
        $this->postJson("/api/sessions/{$ctx['sessionId']}/actions", ['type' => $type, 'payload' => $payload])->assertOk();
    }

    private function stateData(array $ctx): array
    {
        return Session::find($ctx['sessionId'])->state_data;
    }

    private function seekerActions(array $ctx): array
    {
        Sanctum::actingAs($ctx['seeker']);

        return $this->getJson("/api/sessions/{$ctx['sessionId']}/state")->json('available_actions');
    }

    public function test_every_curse_drives_its_gameplay_consequence(): void
    {
        $this->seed(CardSeeder::class);

        foreach (Card::where('type', 'curse')->orderBy('sort')->get() as $curse) {
            $ctx = $this->seek();
            $this->giveHiderCard($ctx['sessionId'], ['uid' => 'c', 'type' => 'curse', 'curse_id' => $curse->id]);
            $effect = $curse->effect ?? [];
            $playPayload = ['card_uid' => 'c'];
            if (! empty($effect['hider_photo'])) {
                $playPayload['photo_url'] = 'https://example.com/streetview.jpg';
            }
            $this->play($ctx, 'play_curse', $playPayload);

            $sd = $this->stateData($ctx);
            $key = $curse->key;

            if (! empty($effect['hider_photo'])) {
                $played = end($sd['curses_played']);
                $this->assertSame('https://example.com/streetview.jpg', $played['hint_photo_url'] ?? null, "{$key} should hand the hider's photo to the seekers");
            }

            $disable = $effect['disable_categories'] ?? null;
            if ($disable && ($disable['mode'] ?? 'random') === 'choose') {
                // Drained Brain: the hider must pick categories.
                $this->assertSame((int) $disable['count'], (int) ($sd['pending_curse_choice']['count'] ?? 0), "{$key} should open a category choice");
            } elseif ($disable) {
                // Spotty Memory: a category is disabled immediately.
                $this->assertNotEmpty($sd['spotty_category'] ?? null, "{$key} should disable a category");
            } elseif (! empty($effect['bonus_draws'])) {
                // Overflowing Chalice: a self-buff applied at once.
                $this->assertSame((int) $effect['bonus_draws']['count'], (int) ($sd['bonus_draws'] ?? 0), "{$key} should grant bonus draws");
            } else {
                // Every other curse becomes an active card on the seekers.
                $active = collect($sd['curses_played'] ?? [])->firstWhere('curse_id', $curse->id);
                $this->assertNotNull($active, "{$key} should be an active curse");
                $this->assertSame((bool) ($effect['requires_proof'] ?? false), (bool) $active['requires_proof'], "{$key} requires_proof");
                $this->assertSame($effect['dice'] ?? null, $active['dice'], "{$key} dice");
                if (! empty($effect['duration_s'])) {
                    $this->assertNotNull($active['expires_at'], "{$key} should expire");
                }
                if (! empty($effect['blocks_asking'])) {
                    $actions = $this->seekerActions($ctx);
                    $this->assertNotContains('ask_question', $actions, "{$key} should block asking");
                    // Dice curses clear by rolling; the rest by marking done (with a photo if required).
                    $clear = ! empty($effect['dice']) ? 'roll_dice' : 'complete_curse';
                    $this->assertContains($clear, $actions, "{$key} should be clearable via {$clear}");
                }
            }
        }
    }

    public function test_every_powerup_plays_and_takes_effect(): void
    {
        $this->seed(CardSeeder::class);

        foreach (Card::where('type', 'powerup')->orderBy('sort')->get() as $card) {
            $power = $card->power;
            $ctx = $this->seek();

            // Veto needs a pending question to refuse.
            if ($power === 'veto') {
                $q = Question::create(['key' => "radar.{$power}", 'category' => 'radar', 'title' => ['en' => 'R'], 'prompt' => ['en' => '?'], 'reward_draw' => 1, 'reward_keep' => 1]);
                Sanctum::actingAs($ctx['seeker']);
                $this->postJson("/api/sessions/{$ctx['sessionId']}/actions", ['type' => 'ask_question', 'payload' => ['question_id' => $q->id, 'radius_m' => 5000]])->assertOk();
            }
            // Duplicate needs a target card already in hand.
            $extra = [];
            if ($power === 'duplicate') {
                $this->giveHiderCard($ctx['sessionId'], ['uid' => 'mk', 'type' => 'time_bonus', 'minutes' => 5]);
                $extra = ['target_uid' => 'mk'];
            }
            if ($power === 'randomize') {
                $this->giveHiderCard($ctx['sessionId'], ['uid' => 'mk', 'type' => 'time_bonus', 'minutes' => 5]);
            }

            $this->giveHiderCard($ctx['sessionId'], ['uid' => 'p', 'type' => 'powerup', 'power' => $power]);
            $this->play($ctx, 'play_powerup', ['card_uid' => 'p'] + $extra);

            $sd = $this->stateData($ctx);
            $hand = $sd['hand'] ?? [];
            $uids = array_map(fn ($c) => $c['uid'] ?? null, $hand);
            $this->assertNotContains('p', $uids, "{$power} card should be consumed");

            match ($power) {
                'veto' => $this->assertNull($sd['pending_question'] ?? null, 'veto clears the pending question'),
                'duplicate' => $this->assertCount(2, $hand, 'duplicate copies the target card'),
                'randomize' => $this->assertNotContains('mk', $uids, 'randomize replaces the hand'),
                'move' => $this->assertTrue($sd['relocating'] ?? false, 'move puts the hider into relocating'),
                'discard_1_draw_2' => $this->assertCount(2, $sd['pending_draw']['cards'] ?? [], 'draws 2'),
                'discard_2_draw_3' => $this->assertCount(3, $sd['pending_draw']['cards'] ?? [], 'draws 3'),
                'draw_1_expand_1' => $this->assertCount(1, $sd['pending_draw']['cards'] ?? [], 'draws 1'),
                default => $this->fail("Unhandled powerup {$power}"),
            };
        }
    }

    public function test_every_time_bonus_tier_banks_its_minutes_for_the_play_size(): void
    {
        $this->seed(CardSeeder::class);

        // startSeeking() uses a small game, so the small-size value is what banks.
        foreach (Card::where('type', 'time_bonus')->orderBy('sort')->get() as $card) {
            $ctx = $this->seek();
            $this->giveHiderCard($ctx['sessionId'], ['uid' => 't', 'type' => 'time_bonus', 'minutes' => $card->minutes]);

            Sanctum::actingAs($ctx['host']);
            $banked = $this->getJson("/api/sessions/{$ctx['sessionId']}/state")->json('time_bonus_s');
            $expected = $card->minutes['small'] * 60;
            $this->assertSame($expected, $banked, "card {$card->key} should bank its small-size value");
        }
    }

    public function test_time_bonus_value_depends_on_the_play_size(): void
    {
        $ctx = $this->seek(); // small game
        $this->giveHiderCard($ctx['sessionId'], ['uid' => 't', 'type' => 'time_bonus', 'minutes' => ['small' => 2, 'medium' => 5, 'large' => 10]]);
        $banked = fn () => $this->getJson("/api/sessions/{$ctx['sessionId']}/state")->json('time_bonus_s');
        Sanctum::actingAs($ctx['host']);

        $this->assertSame(120, $banked(), 'small → 2 min');

        foreach (['medium' => 300, 'large' => 600] as $size => $seconds) {
            $session = Session::find($ctx['sessionId']);
            $session->update(['config' => array_merge($session->config, ['game_size' => $size])]);
            $this->assertSame($seconds, $banked(), "{$size} → {$seconds}s");
        }
    }

    public function test_card_names_render_in_hungarian_and_english_during_a_game(): void
    {
        $this->seed(CardSeeder::class);
        $ctx = $this->seek();
        $labyrinth = Card::where('key', 'the_labyrinth')->firstOrFail();

        // Play a curse → it shows on the seekers' /state, localized.
        $this->giveHiderCard($ctx['sessionId'], ['uid' => 'c', 'type' => 'curse', 'curse_id' => $labyrinth->id]);
        $this->play($ctx, 'play_curse', ['card_uid' => 'c']);

        Sanctum::actingAs($ctx['seeker']);
        $hu = collect($this->withHeader('Accept-Language', 'hu')->getJson("/api/sessions/{$ctx['sessionId']}/state")->json('curses'))->firstWhere('curse_id', $labyrinth->id);
        $en = collect($this->withHeader('Accept-Language', 'en')->getJson("/api/sessions/{$ctx['sessionId']}/state")->json('curses'))->firstWhere('curse_id', $labyrinth->id);
        $this->assertSame('A labirintus', $hu['name']);
        $this->assertSame('The Labyrinth', $en['name']);

        // The hider's hand localizes a curse, a powerup, and a time-bonus.
        $this->giveHiderCard($ctx['sessionId'], ['uid' => 'h1', 'type' => 'curse', 'curse_id' => $labyrinth->id]);
        $this->giveHiderCard($ctx['sessionId'], ['uid' => 'h2', 'type' => 'powerup', 'power' => 'veto']);
        $this->giveHiderCard($ctx['sessionId'], ['uid' => 'h3', 'type' => 'time_bonus', 'minutes' => 5]);

        Sanctum::actingAs($ctx['host']);
        $hand = collect($this->withHeader('Accept-Language', 'hu')->getJson("/api/sessions/{$ctx['sessionId']}/state")->json('hand'))->keyBy('uid');
        $this->assertSame('A labirintus', $hand['h1']['name']);
        $this->assertSame('Vétó', $hand['h2']['name']);
        $this->assertSame('+5 perc időbónusz', $hand['h3']['name']);
    }
}
