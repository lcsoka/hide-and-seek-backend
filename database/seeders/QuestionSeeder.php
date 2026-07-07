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
        'Movie Theater' => 'movie_theater', 'Body of Water' => 'body_of_water',
        'Mountain' => 'mountain', 'Foreign Consulate' => 'consulate',
    ];

    /** Administrative-division matching subject => OSM admin_level (Hungary ladder). */
    private array $adminLevels = [
        '1st Administrative Division' => 6, // megye / county
        '2nd Administrative Division' => 7, // járás / district
        '3rd Administrative Division' => 8, // település / town
        '4th Administrative Division' => 9, // kerület / borough
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
                $this->matchingParams($s));
        }

        $measuring = ['Commercial Airport', 'High-Speed Train Line', 'Rail Station', 'International Border', '1st Administrative Division Border', '2nd Administrative Division Border', 'Sea Level', 'Body of Water', 'Coastline', 'Mountain', 'Park', 'Amusement Park', 'Zoo', 'Aquarium', 'Golf Course', 'Museum', 'Movie Theater', 'Hospital', 'Library', 'Foreign Consulate'];
        foreach ($measuring as $s) {
            $this->seed(QuestionCategory::Measuring, "measuring.{$this->slug($s)}", 3, 1,
                "Measuring — {$s}", "Mérés — {$this->hu($s)}",
                "Compared to me, are you closer to or further from the nearest {$s}?",
                "Hozzám képest közelebb vagy távolabb van a legközelebbi {$this->hu($s)}?",
                $this->measuringParams($s));
        }

        $this->seed(QuestionCategory::Radar, 'radar', 2, 1, 'Radar', 'Radar',
            'Are you within a chosen distance of me?', 'A választott távolságon belül vagy tőlem?',
            ['distances' => ['500 m', '1 km', '2 km', '5 km', '10 km', '25 km', '50 km', 'choose']]);

        $this->seed(QuestionCategory::Thermometer, 'thermometer', 2, 1, 'Thermometer', 'Hőmérő',
            'After I travel at least the chosen distance, am I hotter or colder?',
            'Miután legalább a választott távolságot megteszem, melegebb vagy hidegebb vagyok?',
            ['distances' => ['small' => ['500 m', '3 km'], 'medium' => ['500 m', '3 km', '10 km'], 'large' => ['500 m', '3 km', '10 km', '50 km']]]);

        $photo = ['Building from Station', 'Widest Street', 'Tree', 'Tallest Structure', 'Selfie', 'Sky', 'Tallest Building from Station', 'Street Trace', 'Two Buildings', 'Restaurant Interior', 'Park', 'Grocery Aisle', 'Place of Worship', 'Train Platform', 'Largest Body of Water', 'Five Buildings'];
        foreach ($photo as $s) {
            $this->seed(QuestionCategory::Photo, "photo.{$this->slug($s)}", 1, 1,
                "Photo — {$s}", "Fotó — {$this->hu($s)}",
                "Send a photo of: {$s}.", "Küldj egy fotót erről: {$this->hu($s)}.");
        }

        // The distance is part of the key/title/prompt, so re-seeding with metric distances would
        // orphan the old imperial rows — drop the official tentacles first (custom ones are kept).
        Question::where('category', QuestionCategory::Tentacles->value)->where('is_custom', false)->delete();

        // Metric distances (the game is metric): dense features have a short reach, sparse a long one.
        $tentacles = [
            ['Museums', '2 km', 2000], ['Libraries', '2 km', 2000], ['Movie Theaters', '2 km', 2000], ['Hospitals', '2 km', 2000],
            ['Metro Lines', '25 km', 25000], ['Zoos', '25 km', 25000], ['Aquariums', '25 km', 25000], ['Amusement Parks', '25 km', 25000],
        ];
        foreach ($tentacles as [$s, $radius, $meters]) {
            $this->seed(QuestionCategory::Tentacles, "tentacles.{$this->slug($s)}_{$this->slug($radius)}", 4, 2,
                "Tentacles — {$s} ({$radius})", "Csápok — {$this->hu($s)} ({$radius})",
                "Of all {$s} within {$radius} of a seeker, which is your nearest?",
                "A keresőtől {$radius} távolságon belüli összes {$this->hu($s)} közül melyik a legközelebbi hozzád?",
                $this->tentacleParams($s, $radius, $meters));
        }

        $this->deactivateUnanswerable();
    }

    /**
     * Hide from the picker the questions we can't reliably auto-answer on OSM in Hungary — the rows
     * stay (custom copies, admin re-enable) but `is_active=false`. Manual-only subjects have no OSM
     * geometry; body_of_water/mountain are unreliable (`natural=water/peak` matches tiny fountains
     * and hillocks, not the Danube / real mountains); járás containment is spotty (admin_level=7 is
     * mapped as sparse court districts). Line-feature questions (transit line, street) await the
     * nearest-line engine.
     */
    private function deactivateUnanswerable(): void
    {
        Question::whereIn('key', [
            'matching.transit_line', 'matching.street_or_path', 'matching.landmass',
            'matching.2nd_administrative_division', 'matching.mountain',
            'measuring.high_speed_train_line', 'measuring.sea_level', 'measuring.coastline',
            'measuring.body_of_water', 'measuring.mountain',
            'tentacles.metro_lines_25_km',
        ])->where('is_custom', false)->update(['is_active' => false]);
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

    /** Measuring subject params: distance to a border (admin_level line), else to a point feature. */
    private function measuringParams(string $subject): ?array
    {
        return match ($subject) {
            'International Border' => ['boundary_level' => 2],
            '1st Administrative Division Border' => ['boundary_level' => 6], // megye / county line
            '2nd Administrative Division Border' => ['boundary_level' => 7], // járás / district line
            default => $this->featureParams($subject),
        };
    }

    /** Matching subject params: a point feature, an admin level, or a derived-attribute compare. */
    private function matchingParams(string $subject): ?array
    {
        // "Same length of station name?" — compare the nearest station's NAME length, not identity.
        if ($subject === "Station Name's Length") {
            return ['feature' => 'rail_station', 'match' => 'name_length'];
        }

        return isset($this->adminLevels[$subject])
            ? ['admin_level' => $this->adminLevels[$subject]]
            : $this->featureParams($subject);
    }

    private function tentacleParams(string $subject, string $radius, int $meters): array
    {
        $params = ['radius' => $radius, 'radius_m' => $meters];

        if (isset($this->tentacleFeatureKeys[$subject])) {
            $params['feature'] = $this->tentacleFeatureKeys[$subject];
        }

        return $params;
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
