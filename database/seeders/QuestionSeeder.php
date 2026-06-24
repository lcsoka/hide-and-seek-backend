<?php

namespace Database\Seeders;

use App\Enums\QuestionCategory;
use App\Models\Question;
use Illuminate\Database\Seeder;

class QuestionSeeder extends Seeder
{
    /**
     * Official Jet Lag: Hide + Seek question deck.
     * Source: https://jetlag.denull.ru/en/rules/questions/
     */
    public function run(): void
    {
        $sort = 0;

        // Matching (draw 3 / keep 1): "Is your nearest X the same as mine?"
        $matching = [
            'Commercial Airport', 'Transit Line', "Station Name's Length", 'Street or Path',
            '1st Administrative Division', '2nd Administrative Division', '3rd Administrative Division',
            '4th Administrative Division', 'Mountain', 'Landmass', 'Park', 'Amusement Park', 'Zoo',
            'Aquarium', 'Golf Course', 'Museum', 'Movie Theater', 'Hospital', 'Library', 'Foreign Consulate',
        ];
        foreach ($matching as $subject) {
            $this->seed(QuestionCategory::Matching, "Matching — {$subject}",
                "Is your nearest {$subject} the same as mine?", 3, 1, ++$sort);
        }

        // Measuring (draw 3 / keep 1): "Compared to me, closer or further from X?"
        $measuring = [
            'Commercial Airport', 'High-Speed Train Line', 'Rail Station', 'International Border',
            '1st Administrative Division Border', '2nd Administrative Division Border', 'Sea Level',
            'Body of Water', 'Coastline', 'Mountain', 'Park', 'Amusement Park', 'Zoo', 'Aquarium',
            'Golf Course', 'Museum', 'Movie Theater', 'Hospital', 'Library', 'Foreign Consulate',
        ];
        foreach ($measuring as $subject) {
            $this->seed(QuestionCategory::Measuring, "Measuring — {$subject}",
                "Compared to me, are you closer to or further from the nearest {$subject}?", 3, 1, ++$sort);
        }

        // Radar (draw 2 / keep 1): one card, choose a distance.
        $this->seed(QuestionCategory::Radar, 'Radar', 'Are you within a chosen distance of me?', 2, 1, ++$sort, [
            'distances' => ['1/4 mile', '1/2 mile', '1 mile', '3 miles', '5 miles', '10 miles', '25 miles', '50 miles', '100 miles', 'choose'],
        ]);

        // Thermometer (draw 2 / keep 1): travel a distance, hotter or colder?
        $this->seed(QuestionCategory::Thermometer, 'Thermometer',
            'After I travel at least the chosen distance, am I hotter or colder?', 2, 1, ++$sort, [
                'distances' => ['small' => ['1/2 mile', '3 miles'], 'medium' => ['1/2 mile', '3 miles', '10 miles'], 'large' => ['1/2 mile', '3 miles', '10 miles', '50 miles']],
            ]);

        // Photo (draw 1 / keep 1): "Send a photo of X."
        $photo = [
            'Building from Station', 'Widest Street', 'Tree', 'Tallest Structure', 'Selfie', 'Sky',
            'Tallest Building from Station', 'Street Trace', 'Two Buildings', 'Restaurant Interior',
            'Park', 'Grocery Aisle', 'Place of Worship', 'Train Platform', 'Largest Body of Water', 'Five Buildings',
        ];
        foreach ($photo as $subject) {
            $this->seed(QuestionCategory::Photo, "Photo — {$subject}", "Send a photo of: {$subject}.", 1, 1, ++$sort);
        }

        // Tentacles (draw 4 / keep 2): "Of all X within a radius, which is your nearest?"
        $tentacles = [
            ['Museums', '1 mile'], ['Libraries', '1 mile'], ['Movie Theaters', '1 mile'], ['Hospitals', '1 mile'],
            ['Metro Lines', '15 miles'], ['Zoos', '15 miles'], ['Aquariums', '15 miles'], ['Amusement Parks', '15 miles'],
        ];
        foreach ($tentacles as [$subject, $radius]) {
            $this->seed(QuestionCategory::Tentacles, "Tentacles — {$subject} ({$radius})",
                "Of all {$subject} within {$radius} of a seeker, which is your nearest?", 4, 2, ++$sort,
                ['radius' => $radius]);
        }
    }

    private function seed(QuestionCategory $category, string $title, string $prompt, int $draw, int $keep, int $sort, ?array $parameters = null): void
    {
        Question::updateOrCreate(
            ['category' => $category->value, 'title' => $title],
            [
                'prompt' => $prompt,
                'reward_draw' => $draw,
                'reward_keep' => $keep,
                'parameters' => $parameters,
                'is_custom' => false,
                'is_active' => true,
                'sort' => $sort,
            ],
        );
    }
}
