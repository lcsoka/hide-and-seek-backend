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

/** The stateful deck + the data-driven curse consequences (blocking, category-disable). */
class CardSystemTest extends TestCase
{
    use RefreshDatabase, SeekingScenario;

    private function playCurse(array $ctx, string $key): string
    {
        $curse = Card::where('key', $key)->firstOrFail();
        $uid = 'cx_'.$key;
        $this->giveHiderCard($ctx['sessionId'], ['uid' => $uid, 'type' => 'curse', 'curse_id' => $curse->id]);
        Sanctum::actingAs($ctx['host']);
        $this->postJson("/api/sessions/{$ctx['sessionId']}/actions", ['type' => 'play_curse', 'payload' => ['card_uid' => $uid]])->assertOk();

        return $uid;
    }

    private function seekerActions(array $ctx): array
    {
        Sanctum::actingAs($ctx['seeker']);

        return $this->getJson("/api/sessions/{$ctx['sessionId']}/state")->json('available_actions');
    }

    public function test_a_blocking_curse_stops_questions_until_cleared(): void
    {
        $this->seed(CardSeeder::class);
        $ctx = $this->startSeeking();
        $this->place($ctx, [47.50, 19.04], [47.55, 19.10]);
        $this->assertContains('ask_question', $this->seekerActions($ctx));

        $this->playCurse($ctx, 'the_luxury_car'); // requires_proof + blocks_asking
        $this->assertNotContains('ask_question', $this->seekerActions($ctx));
        $this->assertContains('complete_curse', $this->seekerActions($ctx));

        // The seeker clears it with a photo → asking is allowed again.
        $curseUid = Session::find($ctx['sessionId'])->state_data['curses_played'][0]['uid'];
        Sanctum::actingAs($ctx['seeker']);
        $this->postJson("/api/sessions/{$ctx['sessionId']}/actions", ['type' => 'complete_curse', 'payload' => ['curse_uid' => $curseUid, 'proof_url' => 'http://x/p.jpg']])->assertOk();
        $this->assertContains('ask_question', $this->seekerActions($ctx));
    }

    public function test_spotty_memory_disables_a_category_and_rejects_asking_it(): void
    {
        $this->seed(CardSeeder::class);
        $ctx = $this->startSeeking();
        $this->place($ctx, [47.50, 19.04], [47.55, 19.10]);
        $this->playCurse($ctx, 'spotty_memory');

        Sanctum::actingAs($ctx['seeker']);
        $disabled = $this->getJson("/api/sessions/{$ctx['sessionId']}/state")->json('disabled_categories');
        $this->assertCount(1, $disabled);

        $q = Question::create(['key' => 'q.dis', 'category' => $disabled[0], 'title' => ['en' => 'X'], 'prompt' => ['en' => '?']]);
        $this->postJson("/api/sessions/{$ctx['sessionId']}/actions", ['type' => 'ask_question', 'payload' => ['question_id' => $q->id]])->assertStatus(422);
    }

    public function test_drained_brain_lets_the_hider_pick_three_disabled_categories(): void
    {
        $this->seed(CardSeeder::class);
        $ctx = $this->startSeeking();
        $this->place($ctx, [47.50, 19.04], [47.55, 19.10]);
        $this->playCurse($ctx, 'the_drained_brain');

        Sanctum::actingAs($ctx['host']);
        $st = $this->getJson("/api/sessions/{$ctx['sessionId']}/state");
        $this->assertSame(3, $st->json('curse_choice.count'));
        $this->assertContains('choose_disabled_categories', $st->json('available_actions'));

        $this->postJson("/api/sessions/{$ctx['sessionId']}/actions", ['type' => 'choose_disabled_categories', 'payload' => ['categories' => ['radar', 'matching', 'photo']]])->assertOk();
        Sanctum::actingAs($ctx['seeker']);
        $this->assertEqualsCanonicalizing(['radar', 'matching', 'photo'], $this->getJson("/api/sessions/{$ctx['sessionId']}/state")->json('disabled_categories'));
    }

    public function test_a_card_drawn_in_an_earlier_round_does_not_return_to_the_deck(): void
    {
        // A tiny, all-veto deck (2 copies) so the count is exact.
        Card::create(['type' => 'powerup', 'key' => 'pw.veto', 'name' => ['en' => 'Veto'], 'description' => ['en' => 'x'], 'power' => 'veto', 'count' => 2, 'is_active' => true]);
        $ctx = $this->startSeeking();
        $session = Session::find($ctx['sessionId']);
        $session->update(['config' => array_merge($session->config, ['rounds' => 2])]); // allow a 2nd round
        $this->place($ctx, [47.50, 19.04], [47.55, 19.10]);

        $this->askAndAnswer($ctx, 'radar', ['radius_m' => 5000]); // draws 1 of the 2 cards
        $this->assertCount(1, Session::find($ctx['sessionId'])->state_data['deck']);

        // End round 1 and advance to round 2.
        Sanctum::actingAs($ctx['seeker']);
        $this->postJson("/api/sessions/{$ctx['sessionId']}/actions", ['type' => 'declare_endgame'])->assertOk();
        Sanctum::actingAs($ctx['host']);
        $this->postJson("/api/sessions/{$ctx['sessionId']}/actions", ['type' => 'surrender'])->assertOk();
        $this->postJson("/api/sessions/{$ctx['sessionId']}/actions", ['type' => 'advance_round'])->assertOk();

        // The deck still holds only the ONE remaining card — it was not refilled.
        $this->assertCount(1, Session::find($ctx['sessionId'])->state_data['deck']);
    }
}
