<?php

namespace Tests\Feature;

use App\Enums\QuestionCategory;
use App\Models\Curse;
use App\Models\Question;
use Database\Seeders\CurseSeeder;
use Database\Seeders\QuestionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ContentSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeds_the_official_jet_lag_content(): void
    {
        $this->seed(CurseSeeder::class);
        $this->seed(QuestionSeeder::class);

        $this->assertSame(24, Curse::count());
        $this->assertSame(66, Question::count());

        // All seeded content is official (not custom).
        $this->assertSame(0, Curse::where('is_custom', true)->count());
        $this->assertSame(0, Question::where('is_custom', true)->count());

        // A known curse and every question category are present.
        $this->assertTrue(Curse::where('key', 'the_labyrinth')->exists());
        foreach (QuestionCategory::cases() as $category) {
            $this->assertTrue(
                Question::where('category', $category->value)->exists(),
                "Missing questions for category {$category->value}",
            );
        }
    }

    public function test_seeding_is_idempotent(): void
    {
        $this->seed(CurseSeeder::class);
        $this->seed(CurseSeeder::class);
        $this->seed(QuestionSeeder::class);
        $this->seed(QuestionSeeder::class);

        $this->assertSame(24, Curse::count());
        $this->assertSame(66, Question::count());
    }

    public function test_official_content_is_bilingual(): void
    {
        $this->seed(CurseSeeder::class);
        $this->seed(QuestionSeeder::class);

        $curse = Curse::where('key', 'the_labyrinth')->firstOrFail();
        $this->assertSame('A labirintus', $curse->getTranslation('name', 'hu'));
        $this->assertSame('The Labyrinth', $curse->getTranslation('name', 'en'));

        $question = Question::where('key', 'matching.museum')->firstOrFail();
        $this->assertSame('Egyezés — Múzeum', $question->getTranslation('title', 'hu'));
        $this->assertSame('Matching — Museum', $question->getTranslation('title', 'en'));
    }
}
