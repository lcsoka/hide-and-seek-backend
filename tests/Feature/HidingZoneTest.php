<?php

namespace Tests\Feature;

use App\Game\Geo\ArrayMapDataSource;
use App\Game\Geo\GeoFeature;
use App\Game\Geo\MapDataSource;
use App\Models\Player;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class HidingZoneTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{sessionId: string, hostPlayerId: string, seekerPlayerId: string, host: User, seeker: User} */
    private function toHiding(string $rule = 'circle'): array
    {
        $host = User::factory()->create();
        Sanctum::actingAs($host);
        $create = $this->postJson('/api/sessions', [
            'city' => 'budapest', 'game_size' => 'small',
            'config' => ['rounds' => 1, 'hiding_zone_rule' => $rule],
        ]);
        $sessionId = $create->json('id');
        $hostPlayerId = $create->json('players.0.id');

        $seeker = User::factory()->create();
        Sanctum::actingAs($seeker);
        $seekerPlayerId = $this->postJson("/api/sessions/{$create->json('join_code')}/join", ['display_name' => 'Seeker'])->json('player.id');

        Sanctum::actingAs($host);
        $this->postJson("/api/sessions/{$sessionId}/start");
        $this->postJson("/api/sessions/{$sessionId}/actions", ['type' => 'assign_hider', 'payload' => ['player_id' => $hostPlayerId]]);

        return compact('sessionId', 'hostPlayerId', 'seekerPlayerId', 'host', 'seeker');
    }

    private function bindStations(GeoFeature ...$features): void
    {
        $this->app->instance(MapDataSource::class, new ArrayMapDataSource($features));
    }

    private function station(string $id, float $lat, float $lng): GeoFeature
    {
        return new GeoFeature($id, 'rail_station', $lat, $lng);
    }

    private function chooseAt(array $ctx, float $lat, float $lng)
    {
        Sanctum::actingAs($ctx['host']);

        return $this->postJson("/api/sessions/{$ctx['sessionId']}/actions", ['type' => 'choose_station', 'payload' => ['lat' => $lat, 'lng' => $lng]]);
    }

    private function setHider(array $ctx, float $lat, float $lng): void
    {
        Player::whereKey($ctx['hostPlayerId'])->update(['last_lat' => $lat, 'last_lng' => $lng]);
    }

    private function confirm(array $ctx)
    {
        Sanctum::actingAs($ctx['host']);

        return $this->postJson("/api/sessions/{$ctx['sessionId']}/actions", ['type' => 'confirm_hidden']);
    }

    public function test_circle_zone_allows_confirm_inside(): void
    {
        $ctx = $this->toHiding('circle');
        $this->setHider($ctx, 47.4979, 19.0402);
        $this->chooseAt($ctx, 47.4979, 19.0402)->assertOk();

        $this->confirm($ctx)->assertOk()->assertJsonPath('state', 'seeking');
    }

    public function test_circle_zone_rejects_confirm_outside(): void
    {
        $ctx = $this->toHiding('circle');
        $this->chooseAt($ctx, 47.4979, 19.0402)->assertOk();
        $this->setHider($ctx, 47.6000, 19.2000); // ~20 km away

        $this->confirm($ctx)->assertStatus(422)->assertJsonValidationErrors(['type']);
    }

    public function test_nearest_rule_rejects_when_another_station_is_closer(): void
    {
        $ctx = $this->toHiding('nearest');
        $this->bindStations(
            $this->station('s/chosen', 47.4979, 19.0402),
            $this->station('s/other', 47.4985, 19.0402),
        );
        $this->setHider($ctx, 47.4983, 19.0402); // closer to s/other
        $this->chooseAt($ctx, 47.4979, 19.0402)->assertOk(); // chose s/chosen

        $this->confirm($ctx)->assertStatus(422)->assertJsonValidationErrors(['type']);
    }

    public function test_nearest_rule_allows_when_chosen_station_is_nearest(): void
    {
        $ctx = $this->toHiding('nearest');
        $this->bindStations(
            $this->station('s/chosen', 47.4979, 19.0402),
            $this->station('s/other', 47.6000, 19.2000),
        );
        $this->setHider($ctx, 47.4979, 19.0402);
        $this->chooseAt($ctx, 47.4979, 19.0402)->assertOk();

        $this->confirm($ctx)->assertOk()->assertJsonPath('state', 'seeking');
    }

    public function test_zone_is_visible_to_the_hider_only(): void
    {
        $ctx = $this->toHiding('circle');
        $this->setHider($ctx, 47.4979, 19.0402);
        $this->chooseAt($ctx, 47.4979, 19.0402)->assertOk();

        Sanctum::actingAs($ctx['host']);
        $this->getJson("/api/sessions/{$ctx['sessionId']}/state")
            ->assertOk()->assertJsonPath('hiding_zone.center.lat', 47.4979);

        Sanctum::actingAs($ctx['seeker']);
        $this->getJson("/api/sessions/{$ctx['sessionId']}/state")
            ->assertOk()->assertJsonPath('hiding_zone', null);
    }
}
