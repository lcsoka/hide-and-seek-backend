<?php

namespace Tests\Feature;

use App\Events\GameEventBroadcast;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class EventCatchupTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: string, 1: User, 2: string, 3: User, 4: string} [sessionId, host, hostPid, p2, p2Pid] */
    private function twoPlayerSession(): array
    {
        $host = User::factory()->create();
        Sanctum::actingAs($host);
        $create = $this->postJson('/api/v1/sessions', ['city' => 'budapest', 'game_size' => 'small', 'config' => ['rounds' => 1]]);
        $id = $create->json('id');
        $code = $create->json('join_code');
        $hostPid = $create->json('players.0.id');

        $p2 = User::factory()->create();
        Sanctum::actingAs($p2);
        $join = $this->postJson("/api/v1/sessions/{$code}/join", ['display_name' => 'P2'])->assertOk();
        $p2Pid = $join->json('player.id');

        return [$id, $host, $hostPid, $p2, $p2Pid];
    }

    public function test_meaningful_events_are_recorded_and_returned_in_order(): void
    {
        [$id, $host] = $this->twoPlayerSession(); // join() records a PlayerJoined

        GameEventBroadcast::record($id, 'QuestionAsked', ['seq' => 1]);
        GameEventBroadcast::record($id, 'QuestionAnswered', ['seq' => 1, 'answer' => 'yes']);

        Sanctum::actingAs($host);
        $res = $this->getJson("/api/v1/sessions/{$id}/events?since=0")->assertOk();

        $types = collect($res->json('events'))->pluck('type')->all();
        $this->assertSame(['PlayerJoined', 'QuestionAsked', 'QuestionAnswered'], $types);

        // Monotonic, ascending seqs; cursor is the latest.
        $seqs = collect($res->json('events'))->pluck('seq')->all();
        $this->assertSame($seqs, collect($seqs)->sort()->values()->all());
        $this->assertSame(max($seqs), $res->json('cursor'));
    }

    public function test_since_returns_only_newer_events(): void
    {
        [$id, $host] = $this->twoPlayerSession();
        GameEventBroadcast::record($id, 'QuestionAsked', ['seq' => 1]);

        Sanctum::actingAs($host);
        $all = $this->getJson("/api/v1/sessions/{$id}/events?since=0")->json('events');
        $lastSeq = collect($all)->max('seq');

        $res = $this->getJson("/api/v1/sessions/{$id}/events?since={$lastSeq}")->assertOk();
        $this->assertSame([], $res->json('events'));
        $this->assertSame($lastSeq, $res->json('cursor'));
    }

    public function test_player_scoped_events_only_reach_that_player(): void
    {
        [$id, $host, , $p2, $p2Pid] = $this->twoPlayerSession();
        GameEventBroadcast::record($id, 'SecretPreview', ['x' => 1], ['scope' => 'player', 'player_id' => $p2Pid]);

        Sanctum::actingAs($host);
        $hostTypes = collect($this->getJson("/api/v1/sessions/{$id}/events?since=0")->json('events'))->pluck('type')->all();
        $this->assertNotContains('SecretPreview', $hostTypes);

        Sanctum::actingAs($p2);
        $p2Types = collect($this->getJson("/api/v1/sessions/{$id}/events?since=0")->json('events'))->pluck('type')->all();
        $this->assertContains('SecretPreview', $p2Types);
    }

    public function test_ephemeral_player_moved_is_not_persisted(): void
    {
        [$id, $host, $hostPid] = $this->twoPlayerSession();
        GameEventBroadcast::record($id, 'PlayerMoved', ['player_id' => $hostPid, 'lat' => 47.5, 'lng' => 19.0]);

        Sanctum::actingAs($host);
        $types = collect($this->getJson("/api/v1/sessions/{$id}/events?since=0")->json('events'))->pluck('type')->all();
        $this->assertNotContains('PlayerMoved', $types);
        $this->assertDatabaseMissing('game_events', ['type' => 'PlayerMoved']);
    }
}
