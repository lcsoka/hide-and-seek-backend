<?php

namespace Tests\Feature;

use App\Events\GameEventBroadcast;
use App\Models\Player;
use App\Models\Question;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use Tests\Support\SeekingScenario;
use Tests\TestCase;

/** Every question category, both outcomes — the server-computed answers. */
class QuestionCategoryOutcomesTest extends TestCase
{
    use RefreshDatabase, SeekingScenario;

    protected function setUp(): void
    {
        parent::setUp();
        Event::fake([GameEventBroadcast::class]);
    }

    public function test_radar_yes_when_within_and_no_when_beyond(): void
    {
        $ctx = $this->startSeeking();

        $this->place($ctx, [47.5000, 19.0400], [47.5050, 19.0450]); // ~650 m apart
        $this->askAndAnswer($ctx, 'radar', ['radius_m' => 5000]);
        $this->assertSame('yes', $this->lastAnswer($ctx)['answer']);

        $this->place($ctx, [47.5000, 19.0400], [47.6000, 19.2000]); // ~15 km apart
        $this->askAndAnswer($ctx, 'radar', ['radius_m' => 5000]);
        $this->assertSame('no', $this->lastAnswer($ctx)['answer']);
    }

    public function test_matching_yes_when_shared_nearest_and_no_when_different(): void
    {
        $ctx = $this->startSeeking();
        $this->place($ctx, [47.50, 19.00], [47.60, 19.20]);

        // One museum → both players' nearest is it → yes.
        $this->bindFeatures($this->feature('m/1', 'museum', 47.55, 19.10));
        $this->askAndAnswer($ctx, 'matching', ['feature' => 'museum']);
        $this->assertSame('yes', $this->lastAnswer($ctx)['answer']);

        // A museum next to each player → different nearest → no.
        $this->bindFeatures($this->feature('m/h', 'museum', 47.50, 19.00), $this->feature('m/s', 'museum', 47.60, 19.20));
        $this->askAndAnswer($ctx, 'matching', ['feature' => 'museum']);
        $this->assertSame('no', $this->lastAnswer($ctx)['answer']);
    }

    public function test_measuring_closer_and_further_against_the_seekers_reference(): void
    {
        $ctx = $this->startSeeking();
        // Reference = the airport nearest the SEEKER (the one at 47.60,19.20).
        $this->bindFeatures($this->feature('a/1', 'airport', 47.62, 19.22), $this->feature('a/2', 'airport', 47.30, 18.70));

        // Hider sits almost on the reference airport → closer than the seeker.
        $this->place($ctx, [47.62, 19.22], [47.60, 19.20]);
        $this->askAndAnswer($ctx, 'measuring', ['feature' => 'airport']);
        $this->assertSame('closer', $this->lastAnswer($ctx)['answer']);

        // Hider far from the reference → further than the seeker.
        $this->place($ctx, [47.10, 18.50], [47.60, 19.20]);
        $this->askAndAnswer($ctx, 'measuring', ['feature' => 'airport']);
        $this->assertSame('further', $this->lastAnswer($ctx)['answer']);
    }

    public function test_tentacles_in_range_reveals_nearest_and_out_of_range_otherwise(): void
    {
        $ctx = $this->startSeeking();
        $this->bindFeatures($this->feature('z/1', 'zoo', 47.505, 19.045, 'City Zoo'));

        // Hider within the tentacle radius of the seeker → reveals the nearest zoo.
        $this->place($ctx, [47.5040, 19.0440], [47.5000, 19.0400]);
        $this->askAndAnswer($ctx, 'tentacles', ['feature' => 'zoo', 'radius_m' => 1609]);
        $answer = $this->lastAnswer($ctx);
        $this->assertSame('in_range', $answer['answer']);
        $this->assertSame('City Zoo', $answer['feature_name']);

        // Hider beyond the radius → out of range.
        $this->place($ctx, [47.7000, 19.4000], [47.5000, 19.0400]);
        $this->askAndAnswer($ctx, 'tentacles', ['feature' => 'zoo', 'radius_m' => 1609]);
        $this->assertSame('out_of_range', $this->lastAnswer($ctx)['answer']);
    }

    public function test_thermometer_hotter_and_colder_via_start_stop(): void
    {
        $ctx = $this->startSeeking();
        Player::whereKey($ctx['hiderId'])->update(['last_lat' => 47.50, 'last_lng' => 19.04]);

        // Start far, stop near → hotter.
        $this->thermo($ctx, start: [47.60, 19.20], end: [47.51, 19.05]);
        $this->assertSame('hotter', $this->lastAnswer($ctx)['answer']);

        // Start near, stop far → colder.
        $this->thermo($ctx, start: [47.51, 19.05], end: [47.62, 19.22]);
        $this->assertSame('colder', $this->lastAnswer($ctx)['answer']);
    }

    public function test_photo_is_answered_with_the_uploaded_image(): void
    {
        $ctx = $this->startSeeking();
        $this->place($ctx, [47.50, 19.04], [47.60, 19.20]);
        $this->askAndAnswer($ctx, 'photo', ['photo_url' => 'http://localhost/storage/media/x/p.jpg']);

        $answer = $this->lastAnswer($ctx);
        $this->assertSame('photo', $answer['answer']);
        $this->assertSame('http://localhost/storage/media/x/p.jpg', $answer['photo_url']);
    }

    /** Run a full thermometer: start at one point, travel, stop at another, hider answers. */
    private function thermo(array $ctx, array $start, array $end): void
    {
        $question = Question::create([
            'key' => 'thermometer.'.uniqid(), 'category' => 'thermometer',
            'title' => ['en' => 'Thermometer'], 'prompt' => ['en' => 'Q'], 'reward_draw' => 1, 'reward_keep' => 1,
        ]);

        Player::whereKey($ctx['seekerId'])->update(['last_lat' => $start[0], 'last_lng' => $start[1]]);
        Sanctum::actingAs($ctx['seeker']);
        $this->postJson("/api/sessions/{$ctx['sessionId']}/actions", ['type' => 'start_thermometer', 'payload' => ['question_id' => $question->id, 'distance_m' => 5000]])->assertOk();

        Player::whereKey($ctx['seekerId'])->update(['last_lat' => $end[0], 'last_lng' => $end[1]]);
        $this->postJson("/api/sessions/{$ctx['sessionId']}/actions", ['type' => 'stop_thermometer'])->assertOk();

        Sanctum::actingAs($ctx['host']);
        $this->postJson("/api/sessions/{$ctx['sessionId']}/actions", ['type' => 'answer_question'])->assertOk();
    }
}
