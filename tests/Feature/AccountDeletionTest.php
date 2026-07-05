<?php

namespace Tests\Feature;

use App\Models\Card;
use App\Models\GameResult;
use App\Models\Player;
use App\Models\Session;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AccountDeletionTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_registered_user_can_erase_their_account_and_personal_data(): void
    {
        $user = User::factory()->create(['email' => 'me@example.com', 'password' => Hash::make('secret123')]);
        $session = Session::create(['join_code' => strtoupper(Str::random(6)), 'game_mode' => 'hide_and_seek', 'state' => 'lobby', 'status' => 'open', 'config' => [], 'state_data' => []]);
        $player = Player::create(['session_id' => $session->id, 'user_id' => $user->id, 'display_name' => 'Real Name']);
        GameResult::create(['user_id' => $user->id, 'session_id' => $session->id, 'display_name' => 'Real Name', 'hide_time_s' => 100, 'won' => true, 'players_count' => 2, 'played_at' => now()]);
        $card = Card::create(['key' => 'c.'.uniqid(), 'type' => 'curse', 'name' => ['en' => 'Mine'], 'description' => ['en' => 'x'], 'is_custom' => true, 'user_id' => $user->id]);
        $user->createToken('t');

        Sanctum::actingAs($user);
        $this->deleteJson('/api/v1/profile', ['password' => 'secret123'])->assertOk();

        // The user + their stats + custom content are gone.
        $this->assertDatabaseMissing('users', ['id' => $user->id]);
        $this->assertDatabaseMissing('game_results', ['user_id' => $user->id]);
        $this->assertDatabaseMissing('cards', ['id' => $card->id]);
        $this->assertSame(0, $user->tokens()->count());

        // The player record survives (co-players' history) but is anonymised.
        $player->refresh();
        $this->assertNull($player->user_id);
        $this->assertSame('Deleted player', $player->display_name);
    }

    public function test_deletion_is_rejected_without_the_correct_password(): void
    {
        $user = User::factory()->create(['email' => 'me@example.com', 'password' => Hash::make('secret123')]);
        Sanctum::actingAs($user);

        $this->deleteJson('/api/v1/profile', ['password' => 'wrong'])->assertStatus(422);
        $this->assertDatabaseHas('users', ['id' => $user->id]);
    }

    public function test_a_guest_can_delete_without_a_password(): void
    {
        $guest = User::factory()->create(['email' => null, 'password' => null]);
        Sanctum::actingAs($guest);

        $this->deleteJson('/api/v1/profile')->assertOk();
        $this->assertDatabaseMissing('users', ['id' => $guest->id]);
    }
}
