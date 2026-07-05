<?php

namespace Tests\Feature;

use App\Jobs\SendWebPush;
use App\Models\Player;
use App\Models\PushSubscription;
use App\Models\Session;
use App\Models\User;
use App\Support\PushNotifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class WebPushTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Pretend VAPID is configured so PushNotifier actually dispatches.
        config(['webpush.vapid.public_key' => 'test-public', 'webpush.vapid.private_key' => 'test-private']);
    }

    private function runningSession(): Session
    {
        return Session::create([
            'join_code' => strtoupper(Str::random(6)), 'game_mode' => 'hide_and_seek',
            'state' => 'in_round', 'status' => 'running', 'config' => [], 'state_data' => [],
        ]);
    }

    // ── subscription endpoints ───────────────────────────────────────────────

    public function test_public_key_endpoint_returns_the_configured_key(): void
    {
        config(['webpush.vapid.public_key' => 'BXYZ']);
        $this->getJson('/api/v1/push/public-key')->assertOk()->assertJsonPath('key', 'BXYZ');
    }

    public function test_a_user_can_subscribe_and_unsubscribe_a_device(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $payload = ['endpoint' => 'https://push.example/abc', 'keys' => ['p256dh' => 'pub', 'auth' => 'sec'], 'locale' => 'en'];
        $this->postJson('/api/v1/push/subscribe', $payload)->assertOk();
        $this->assertDatabaseHas('push_subscriptions', ['endpoint' => 'https://push.example/abc', 'user_id' => $user->id, 'locale' => 'en']);

        // Re-subscribing the same endpoint updates rather than duplicates.
        $this->postJson('/api/v1/push/subscribe', $payload)->assertOk();
        $this->assertSame(1, PushSubscription::count());

        $this->postJson('/api/v1/push/unsubscribe', ['endpoint' => 'https://push.example/abc'])->assertOk();
        $this->assertSame(0, PushSubscription::count());
    }

    public function test_subscribe_requires_authentication(): void
    {
        $this->postJson('/api/v1/push/subscribe', ['endpoint' => 'x', 'keys' => ['p256dh' => 'a', 'auth' => 'b']])->assertUnauthorized();
    }

    // ── recipient routing ────────────────────────────────────────────────────

    public function test_question_asked_notifies_only_the_hider(): void
    {
        Queue::fake();
        $session = $this->runningSession();
        $hider = User::factory()->create();
        $seeker = User::factory()->create();
        $hiderP = Player::create(['session_id' => $session->id, 'user_id' => $hider->id, 'display_name' => 'H', 'role' => 'hider']);
        $seekerP = Player::create(['session_id' => $session->id, 'user_id' => $seeker->id, 'display_name' => 'S', 'role' => 'seeker']);

        // A seeker asks → only the hider is pushed.
        app(PushNotifier::class)->forGameEvent($session, 'QuestionAsked', $seekerP->id);

        Queue::assertPushed(SendWebPush::class, fn (SendWebPush $j) => $j->key === 'push.question_asked' && $j->userIds === [$hider->id]);
    }

    public function test_round_started_notifies_all_players(): void
    {
        Queue::fake();
        $session = $this->runningSession();
        $a = User::factory()->create();
        $b = User::factory()->create();
        Player::create(['session_id' => $session->id, 'user_id' => $a->id, 'display_name' => 'A', 'role' => 'hider']);
        Player::create(['session_id' => $session->id, 'user_id' => $b->id, 'display_name' => 'B', 'role' => 'seeker']);

        app(PushNotifier::class)->forGameEvent($session, 'RoundStarted', null);

        Queue::assertPushed(SendWebPush::class, function (SendWebPush $j) use ($a, $b) {
            sort($j->userIds);
            $expected = [$a->id, $b->id];
            sort($expected);

            return $j->key === 'push.round_started' && $j->userIds === $expected;
        });
    }

    public function test_lobby_join_notifies_the_host_only(): void
    {
        Queue::fake();
        $session = $this->runningSession();
        $host = User::factory()->create();
        Player::create(['session_id' => $session->id, 'user_id' => $host->id, 'display_name' => 'Host', 'is_host' => true, 'role' => 'seeker']);
        $joiner = Player::create(['session_id' => $session->id, 'user_id' => User::factory()->create()->id, 'display_name' => 'New']);

        app(PushNotifier::class)->forLobbyJoin($session->refresh(), $joiner);

        Queue::assertPushed(SendWebPush::class, fn (SendWebPush $j) => $j->key === 'push.player_joined' && $j->userIds === [$host->id]);
    }

    public function test_nothing_is_sent_when_vapid_is_not_configured(): void
    {
        config(['webpush.vapid.public_key' => '', 'webpush.vapid.private_key' => '']);
        Queue::fake();
        $session = $this->runningSession();
        Player::create(['session_id' => $session->id, 'user_id' => User::factory()->create()->id, 'display_name' => 'H', 'role' => 'hider']);

        app(PushNotifier::class)->forGameEvent($session, 'QuestionAsked', null);

        Queue::assertNothingPushed();
    }
}
