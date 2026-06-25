<?php

namespace Database\Seeders;

use App\Enums\QuestionCategory;
use App\Models\Question;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class QuestionSeeder extends Seeder
{
    /**
     * Official Jet Lag: Hide + Seek question deck (bilingual en/hu).
     * Source: https://jetlag.denull.ru/en/rules/questions/
     * Hungarian is a first pass — refine wording in the admin (locale-aware).
     */
    private int $sort = 0;

    /** English subject => Hungarian subject. */
    private array $hu = [
        'Commercial Airport' => 'Kereskedelmi repülőtér', 'Transit Line' => 'Tömegközlekedési vonal',
        "Station Name's Length" => 'Állomásnév hossza', 'Street or Path' => 'Utca vagy ösvény',
        '1st Administrative Division' => '1. közigazgatási egység', '2nd Administrative Division' => '2. közigazgatási egység',
        '3rd Administrative Division' => '3. közigazgatási egység', '4th Administrative Division' => '4. közigazgatási egység',
        'Mountain' => 'Hegy', 'Landmass' => 'Szárazföld', 'Park' => 'Park', 'Amusement Park' => 'Vidámpark',
        'Zoo' => 'Állatkert', 'Aquarium' => 'Akvárium', 'Golf Course' => 'Golfpálya', 'Museum' => 'Múzeum',
        'Movie Theater' => 'Mozi', 'Hospital' => 'Kórház', 'Library' => 'Könyvtár', 'Foreign Consulate' => 'Külföldi konzulátus',
        'High-Speed Train Line' => 'Nagysebességű vasútvonal', 'Rail Station' => 'Vasútállomás',
        'International Border' => 'Nemzetközi határ', '1st Administrative Division Border' => '1. közigazgatási egység határa',
        '2nd Administrative Division Border' => '2. közigazgatási egység határa', 'Sea Level' => 'Tengerszint',
        'Body of Water' => 'Víztest', 'Coastline' => 'Tengerpart',
        'Building from Station' => 'Épület az állomásról', 'Widest Street' => 'Legszélesebb utca', 'Tree' => 'Fa',
        'Tallest Structure' => 'Legmagasabb építmény', 'Selfie' => 'Szelfi', 'Sky' => 'Égbolt',
        'Tallest Building from Station' => 'Legmagasabb épület az állomásról', 'Street Trace' => 'Utcarészlet',
        'Two Buildings' => 'Két épület', 'Restaurant Interior' => 'Étterem belső tere', 'Grocery Aisle' => 'Élelmiszerbolti polcsor',
        'Place of Worship' => 'Istentiszteleti hely', 'Train Platform' => 'Vasúti peron', 'Largest Body of Water' => 'Legnagyobb víztest',
        'Five Buildings' => 'Öt épület',
        'Museums' => 'Múzeumok', 'Libraries' => 'Könyvtárak', 'Movie Theaters' => 'Mozik', 'Hospitals' => 'Kórházak',
        'Metro Lines' => 'Metróvonalak', 'Zoos' => 'Állatkertek', 'Aquariums' => 'Akváriumok', 'Amusement Parks' => 'Vidámparkok',
    ];

    /** Matching/measuring subject => OSM feature key (config game.overpass.features). */
    private array $featureKeys = [
        'Commercial Airport' => 'airport', 'Rail Station' => 'rail_station', 'Museum' => 'museum',
        'Park' => 'park', 'Hospital' => 'hospital', 'Library' => 'library', 'Zoo' => 'zoo',
        'Aquarium' => 'aquarium', 'Amusement Park' => 'amusement_park', 'Golf Course' => 'golf_course',
        'Movie Theater' => 'movie_theater',
    ];

    /** Tentacle (plural) subject => OSM feature key. */
    private array $tentacleFeatureKeys = [
        'Museums' => 'museum', 'Libraries' => 'library', 'Movie Theaters' => 'movie_theater',
        'Hospitals' => 'hospital', 'Zoos' => 'zoo', 'Aquariums' => 'aquarium', 'Amusement Parks' => 'amusement_park',
    ];

    public function run(): void
    {
        $matching = ['Commercial Airport', 'Transit Line', "Station Name's Length", 'Street or Path', '1st Administrative Division', '2nd Administrative Division', '3rd Administrative Division', '4th Administrative Division', 'Mountain', 'Landmass', 'Park', 'Amusement Park', 'Zoo', 'Aquarium', 'Golf Course', 'Museum', 'Movie Theater', 'Hospital', 'Library', 'Foreign Consulate'];
        foreach ($matching as $s) {
            $this->seed(QuestionCategory::Matching, "matching.{$this->slug($s)}", 3, 1,
                "Matching — {$s}", "Egyezés — {$this->hu($s)}",
                "Is your nearest {$s} the same as mine?", "A legközelebbi {$this->hu($s)} ugyanaz, mint az enyém?",
                $this->featureParams($s));
        }

        $measuring = ['Commercial Airport', 'High-Speed Train Line', 'Rail Station', 'International Border', '1st Administrative Division Border', '2nd Administrative Division Border', 'Sea Level', 'Body of Water', 'Coastline', 'Mountain', 'Park', 'Amusement Park', 'Zoo', 'Aquarium', 'Golf Course', 'Museum', 'Movie Theater', 'Hospital', 'Library', 'Foreign Consulate'];
        foreach ($measuring as $s) {
            $this->seed(QuestionCategory::Measuring, "measuring.{$this->slug($s)}", 3, 1,
                "Measuring — {$s}", "Mérés — {$this->hu($s)}",
                "Compared to me, are you closer to or further from the nearest {$s}?",
                "Hozzám képest közelebb vagy távolabb van a legközelebbi {$this->hu($s)}?",
                $this->featureParams($s));
        }

        $this->seed(QuestionCategory::Radar, 'radar', 2, 1, 'Radar', 'Radar',
            'Are you within a chosen distance of me?', 'A választott távolságon belül vagy tőlem?',
            ['distances' => ['1/4 mile', '1/2 mile', '1 mile', '3 miles', '5 miles', '10 miles', '25 miles', '50 miles', '100 miles', 'choose']]);

        $this->seed(QuestionCategory::Thermometer, 'thermometer', 2, 1, 'Thermometer', 'Hőmérő',
            'After I travel at least the chosen distance, am I hotter or colder?',
            'Miután legalább a választott távolságot megteszem, melegebb vagy hidegebb vagyok?',
            ['distances' => ['small' => ['1/2 mile', '3 miles'], 'medium' => ['1/2 mile', '3 miles', '10 miles'], 'large' => ['1/2 mile', '3 miles', '10 miles', '50 miles']]]);

        $photo = ['Building from Station', 'Widest Street', 'Tree', 'Tallest Structure', 'Selfie', 'Sky', 'Tallest Building from Station', 'Street Trace', 'Two Buildings', 'Restaurant Interior', 'Park', 'Grocery Aisle', 'Place of Worship', 'Train Platform', 'Largest Body of Water', 'Five Buildings'];
        foreach ($photo as $s) {
            $this->seed(QuestionCategory::Photo, "photo.{$this->slug($s)}", 1, 1,
                "Photo — {$s}", "Fotó — {$this->hu($s)}",
                "Send a photo of: {$s}.", "Küldj egy fotót erről: {$this->hu($s)}.");
        }

        $tentacles = [['Museums', '1 mile'], ['Libraries', '1 mile'], ['Movie Theaters', '1 mile'], ['Hospitals', '1 mile'], ['Metro Lines', '15 miles'], ['Zoos', '15 miles'], ['Aquariums', '15 miles'], ['Amusement Parks', '15 miles']];
        foreach ($tentacles as [$s, $radius]) {
            $this->seed(QuestionCategory::Tentacles, "tentacles.{$this->slug($s)}_{$this->slug($radius)}", 4, 2,
                "Tentacles — {$s} ({$radius})", "Csápok — {$this->hu($s)} ({$radius})",
                "Of all {$s} within {$radius} of a seeker, which is your nearest?",
                "A keresőtől {$radius} távolságon belüli összes {$this->hu($s)} közül melyik a legközelebbi hozzád?",
                $this->tentacleParams($s, $radius));
        }
    }

    private function hu(string $subject): string
    {
        return $this->hu[$subject] ?? $subject;
    }

    private function slug(string $value): string
    {
        return Str::slug($value, '_');
    }

    private function featureParams(string $subject): ?array
    {
        return isset($this->featureKeys[$subject]) ? ['feature' => $this->featureKeys[$subject]] : null;
    }

    private function tentacleParams(string $subject, string $radius): array
    {
        $params = ['radius' => $radius, 'radius_m' => $this->radiusMeters($radius)];

        if (isset($this->tentacleFeatureKeys[$subject])) {
            $params['feature'] = $this->tentacleFeatureKeys[$subject];
        }

        return $params;
    }

    private function radiusMeters(string $radius): int
    {
        preg_match('/[\d.]+/', $radius, $match);

        return (int) round(((float) ($match[0] ?? 0)) * 1609.34);
    }

    private function seed(QuestionCategory $category, string $key, int $draw, int $keep, string $titleEn, string $titleHu, string $promptEn, string $promptHu, ?array $parameters = null): void
    {
        Question::updateOrCreate(
            ['key' => $key],
            [
                'category' => $category->value,
                'title' => ['en' => $titleEn, 'hu' => $titleHu],
                'prompt' => ['en' => $promptEn, 'hu' => $promptHu],
                'reward_draw' => $draw,
                'reward_keep' => $keep,
                'answer_time_s' => $this->answerTime($category),
                'parameters' => $parameters,
                'is_custom' => false,
                'is_active' => true,
                'sort' => ++$this->sort,
            ],
        );
    }

    /** Per-category seconds the hider gets to answer (cheap/geometric = quick; manual = long). */
    private function answerTime(QuestionCategory $category): int
    {
        return match ($category) {
            QuestionCategory::Radar => 180,
            QuestionCategory::Thermometer => 300,
            QuestionCategory::Matching, QuestionCategory::Measuring => 300,
            QuestionCategory::Tentacles => 480,
            QuestionCategory::Photo => 600,
        };
    }
}
