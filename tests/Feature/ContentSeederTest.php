<?php

namespace Tests\Feature;

use App\Enums\QuestionCategory;
use App\Models\Card;
use App\Models\Question;
use Database\Seeders\CardSeeder;
use Database\Seeders\QuestionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ContentSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeds_the_official_jet_lag_content(): void
    {
        $this->seed(CardSeeder::class);
        $this->seed(QuestionSeeder::class);

        // The official deck: 24 curses + 7 powerup rows + 13 time-bonus rows.
        $this->assertSame(24, Card::where('type', 'curse')->count());
        $this->assertSame(7, Card::where('type', 'powerup')->count());
        $this->assertSame(13, Card::where('type', 'time_bonus')->count());
        // …expanding (by `count`) to 70 physical cards: 24 + 21 + 25.
        $this->assertSame(70, (int) Card::sum('count'));
        $this->assertSame(21, (int) Card::where('type', 'powerup')->sum('count'));
        $this->assertSame(25, (int) Card::where('type', 'time_bonus')->sum('count'));
        $this->assertSame(66, Question::count());

        // All seeded content is official (not custom).
        $this->assertSame(0, Card::where('is_custom', true)->count());
        $this->assertSame(0, Question::where('is_custom', true)->count());

        // A known curse and every question category are present.
        $this->assertTrue(Card::where('key', 'the_labyrinth')->exists());
        foreach (QuestionCategory::cases() as $category) {
            $this->assertTrue(
                Question::where('category', $category->value)->exists(),
                "Missing questions for category {$category->value}",
            );
        }
    }

    public function test_seeding_is_idempotent(): void
    {
        $this->seed(CardSeeder::class);
        $this->seed(CardSeeder::class);
        $this->seed(QuestionSeeder::class);
        $this->seed(QuestionSeeder::class);

        $this->assertSame(44, Card::count()); // 24 + 7 + 13 rows, unchanged on re-seed
        $this->assertSame(66, Question::count());
    }

    public function test_official_content_is_bilingual(): void
    {
        $this->seed(CardSeeder::class);
        $this->seed(QuestionSeeder::class);

        $curse = Card::where('key', 'the_labyrinth')->firstOrFail();
        $this->assertSame('A labirintus', $curse->getTranslation('name', 'hu'));
        $this->assertSame('The Labyrinth', $curse->getTranslation('name', 'en'));

        $question = Question::where('key', 'matching.museum')->firstOrFail();
        $this->assertSame('Egyezés — Múzeum', $question->getTranslation('title', 'hu'));
        $this->assertSame('Matching — Museum', $question->getTranslation('title', 'en'));
    }

    public function test_geo_questions_carry_osm_feature_keys(): void
    {
        $this->seed(QuestionSeeder::class);

        $this->assertSame('museum', Question::where('key', 'matching.museum')->firstOrFail()->parameters['feature']);
        $this->assertSame('airport', Question::where('key', 'measuring.commercial_airport')->firstOrFail()->parameters['feature']);

        $tentacle = Question::where('key', 'tentacles.museums_1_mile')->firstOrFail();
        $this->assertSame('museum', $tentacle->parameters['feature']);
        $this->assertGreaterThan(0, $tentacle->parameters['radius_m']);
    }
}
