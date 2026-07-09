<?php

namespace Tests\Feature;

use App\Models\Card;
use App\Models\Session;
use App\Models\User;
use Database\Seeders\CardSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\SeekingScenario;
use Tests\TestCase;

class DeckConfigTest extends TestCase
{
    use RefreshDatabase, SeekingScenario;

    public function test_deck_endpoint_lists_every_official_card_type(): void
    {
        $this->seed(CardSeeder::class);
        Sanctum::actingAs(User::factory()->create());

        $deck = collect($this->getJson('/api/v1/deck')->assertOk()->json());

        $this->assertTrue($deck->contains(fn ($c) => $c['type'] === 'curse'));
        $this->assertTrue($deck->contains(fn ($c) => $c['type'] === 'powerup'));
        $this->assertTrue($deck->contains(fn ($c) => $c['type'] === 'time_bonus'));
        $this->assertTrue($deck->every(fn ($c) => isset($c['id'], $c['name'], $c['type'])));
    }

    public function test_curated_deck_is_stored_and_limits_the_draw_pool(): void
    {
        $this->seed(CardSeeder::class);
        $ctx = $this->startSeeking();
        $keep = Card::where('type', 'curse')->where('is_active', true)->firstOrFail();

        // Curate the deck down to a single curse before anything is drawn.
        $session = Session::find($ctx['sessionId']);
        $data = $session->state_data;
        $data['deck_cards'] = [$keep->id];
        unset($data['deck']); // force a rebuild from the curated set
        $session->update(['state_data' => $data]);

        // A question reward makes the hider draw from the deck.
        $this->place($ctx, [47.50, 19.04], [47.55, 19.10]);
        $this->askAndAnswer($ctx, 'radar', ['radius_m' => 1000]);

        $data = Session::find($ctx['sessionId'])->state_data;
        $drawn = $data['pending_draw']['cards'] ?? [];
        $this->assertNotEmpty($drawn, 'the reward should draw from the curated deck');
        foreach ($drawn as $card) {
            $this->assertSame($keep->id, $card['curse_id'] ?? null, 'only the kept curse can be drawn');
        }
        // Nothing else is left in the (single-card) pool.
        $this->assertEmpty($data['deck'] ?? []);
    }

    public function test_create_persists_the_curated_deck_into_state_data(): void
    {
        $this->seed(CardSeeder::class);
        Sanctum::actingAs(User::factory()->create());
        $keep = Card::where('type', 'curse')->where('is_active', true)->firstOrFail();

        $id = $this->postJson('/api/v1/sessions', [
            'city' => 'budapest',
            'config' => ['deck_cards' => [$keep->id]],
        ])->assertCreated()->json('id');

        $data = Session::find($id)->state_data;
        $this->assertSame([$keep->id], $data['deck_cards'] ?? null);
        // It's kept out of the public config.
        $this->assertArrayNotHasKey('deck_cards', Session::find($id)->config);
    }
}
