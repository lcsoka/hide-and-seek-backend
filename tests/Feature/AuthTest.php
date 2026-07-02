<?php

namespace Tests\Feature;

use App\Models\Player;
use App\Models\Session;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: string, 1: int} [plainTextToken, userId] */
    private function guest(string $name = 'Guest'): array
    {
        $res = $this->postJson('/api/auth/guest', ['display_name' => $name])->assertCreated();

        return [$res->json('token'), $res->json('user_id')];
    }

    public function test_guest_registers_in_place_keeping_the_same_user_and_history(): void
    {
        [$token, $userId] = $this->guest('Alice');

        // The guest already has a player in a game — that history must survive registration.
        $session = Session::create(['join_code' => strtoupper(Str::random(6)), 'game_mode' => 'hide_and_seek', 'state' => 'lobby', 'status' => 'open', 'config' => [], 'state_data' => []]);
        Player::create(['session_id' => $session->id, 'user_id' => $userId, 'display_name' => 'Alice']);

        $this->withToken($token)->postJson('/api/auth/register', ['email' => 'Alice@Example.com', 'password' => 'password123'])
            ->assertCreated()
            ->assertJsonPath('id', $userId)
            ->assertJsonPath('email', 'alice@example.com') // lowercased
            ->assertJsonPath('is_guest', false);

        $this->assertDatabaseHas('users', ['id' => $userId, 'email' => 'alice@example.com']);
        $this->assertDatabaseHas('players', ['user_id' => $userId, 'session_id' => $session->id]);

        // The original token still authenticates the (now registered) user.
        $this->withToken($token)->getJson('/api/auth/me')->assertOk()->assertJsonPath('email', 'alice@example.com');
    }

    public function test_register_rejects_an_already_registered_account(): void
    {
        [$token] = $this->guest();
        $this->withToken($token)->postJson('/api/auth/register', ['email' => 'a@example.com', 'password' => 'password123'])->assertCreated();

        $this->withToken($token)->postJson('/api/auth/register', ['email' => 'b@example.com', 'password' => 'password123'])->assertStatus(409);
    }

    public function test_register_rejects_a_duplicate_email(): void
    {
        [$t1] = $this->guest();
        $this->withToken($t1)->postJson('/api/auth/register', ['email' => 'dup@example.com', 'password' => 'password123'])->assertCreated();

        [$t2] = $this->guest();
        $this->withToken($t2)->postJson('/api/auth/register', ['email' => 'dup@example.com', 'password' => 'password123'])->assertStatus(422);
    }

    public function test_login_returns_a_token_for_valid_credentials(): void
    {
        [$token] = $this->guest();
        $this->withToken($token)->postJson('/api/auth/register', ['email' => 'bob@example.com', 'password' => 'password123'])->assertCreated();

        $this->postJson('/api/auth/login', ['email' => 'BOB@example.com', 'password' => 'password123'])
            ->assertOk()->assertJsonPath('email', 'bob@example.com')->assertJsonStructure(['token']);

        $this->postJson('/api/auth/login', ['email' => 'bob@example.com', 'password' => 'wrong'])->assertStatus(422);
    }

    public function test_forgot_password_emails_a_reset_link(): void
    {
        Notification::fake();
        $user = User::factory()->create();

        $this->postJson('/api/auth/forgot-password', ['email' => $user->email])->assertOk();

        Notification::assertSentTo($user, ResetPassword::class);
    }

    public function test_forgot_password_does_not_reveal_an_unknown_email(): void
    {
        Notification::fake();

        $this->postJson('/api/auth/forgot-password', ['email' => 'nobody@example.com'])->assertOk();

        Notification::assertNothingSent();
    }

    public function test_reset_password_with_a_valid_token_changes_the_password(): void
    {
        $user = User::factory()->create(['password' => 'oldpassword']);
        $token = Password::createToken($user);

        $this->postJson('/api/auth/reset-password', ['token' => $token, 'email' => $user->email, 'password' => 'newpassword123'])
            ->assertOk();

        $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'newpassword123'])->assertOk();
        $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'oldpassword'])->assertStatus(422);
    }

    public function test_reset_password_rejects_an_invalid_token(): void
    {
        $user = User::factory()->create();

        $this->postJson('/api/auth/reset-password', ['token' => 'bogus-token', 'email' => $user->email, 'password' => 'newpassword123'])
            ->assertStatus(422);
    }

    public function test_update_profile_and_avatar(): void
    {
        Storage::fake('public');
        [$token] = $this->guest('Old Name');

        $this->withToken($token)->patchJson('/api/profile', ['name' => 'New Name'])->assertOk()->assertJsonPath('name', 'New Name');

        $res = $this->withToken($token)->post('/api/profile/avatar', ['image' => UploadedFile::fake()->image('me.jpg')])->assertOk();
        $this->assertNotNull($res->json('avatar'));
        $this->assertNotEmpty(Storage::disk('public')->allFiles('avatars'));
    }

    public function test_logout_revokes_the_token(): void
    {
        [$token] = $this->guest();
        $this->withToken($token)->getJson('/api/auth/me')->assertOk();
        $this->assertDatabaseCount('personal_access_tokens', 1);

        $this->withToken($token)->postJson('/api/auth/logout')->assertOk();

        // The token row is deleted, so the bearer token no longer authenticates anything.
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }
}
