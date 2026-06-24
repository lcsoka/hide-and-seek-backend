<?php

namespace Database\Seeders;

use App\Models\Curse;
use Illuminate\Database\Seeder;

class CurseSeeder extends Seeder
{
    /**
     * Official Jet Lag: Hide + Seek curses.
     * Source: https://jetlag.denull.ru/en/rules/curses/ (and the Jet Lag wiki).
     */
    public function run(): void
    {
        $curses = [
            ['The Luxury Car', 'Photo of a car', 'Seekers must photograph a more expensive car before asking questions.'],
            ['The Bridge Troll', 'Distance (varies by game size)', 'Seekers must ask their next question from under a bridge.'],
            ['The Drained Brain', 'Discard entire hand', 'Choose three questions in different categories; the seekers cannot ask those questions.'],
            ['Water Weight', 'Within 300m of a body of water', 'Seekers must carry 2 litres of liquid per person for the remainder of the run.'],
            ['The Zoologist', 'Photo of an animal', 'Seekers must photograph a wild animal in the same category before asking questions.'],
            ['The Egg Partner', 'Discard 2 cards', 'Seekers must acquire and protect an egg as a team member; bonus to the hider if it is abandoned.'],
            ['The Jammed Door', 'Discard 2 cards', 'Seekers must roll a 7+ to enter buildings for the next 0.5–3 hours.'],
            ['Spotty Memory', 'Discard a time-bonus card', 'One random question category is disabled and changes after each question asked.'],
            ['The Bird Guide', 'Film a bird', "Seekers must film a bird for a duration equal to or longer than the hider's footage."],
            ['The Unguided Tourist', 'Seekers must be outside', 'Seekers must locate a Street View image in real life before transit or questions.'],
            ['The Ransom Note', 'Spell out "Ransom Note" physically', 'The next question must be composed from cut-out printed letters or words.'],
            ['The Mediocre Travel Agent', 'Destination further than current location', 'Seekers must visit a nearby location, spend time there, take photos, and get a souvenir.'],
            ['The Impressionable Consumer', 'Next question is free', 'Seekers must visit a location or buy a product advertised 30m+ away.'],
            ['The U-Turn', 'Seekers heading the wrong direction', 'Seekers must disembark at the next station if alternate transit is available.'],
            ['The Cairn', 'Build a rock tower', 'Seekers must build a matching-height rock tower before asking questions.'],
            ['The Distant Cuisine', 'Must be at a restaurant', 'Seekers must visit a restaurant serving equally-distant foreign cuisine.'],
            ['The Lemon Phylactery', 'Discard a powerup card', 'Each seeker must affix a real lemon to their clothes; bonus to the hider if a lemon falls.'],
            ["The Gambler's Feet", 'Roll a die; no effect if even', 'For the next 20–60 minutes seekers must roll a die before steps, moving that many.'],
            ['The Hidden Hangman', 'Discard 2 cards', 'Seekers must play hangman against the hider before asking questions or transit.'],
            ['The Endless Tumble', 'Roll a die; no effect if 5–6', 'Seekers must roll a die 30m+ and land 5–6 before asking questions.'],
            ['The Right Turn', 'Discard 1 card', 'For the next 20–60 minutes seekers can only turn right at intersections.'],
            ['The Urban Explorer', 'Discard 2 cards', 'Seekers cannot ask questions while on transit or in train stations.'],
            ['The Overflowing Chalice', 'Discard 1 card', 'For the next three questions, the hider may draw an additional card from the hider deck.'],
            ['The Labyrinth', 'Draw a maze', 'Seekers must solve a hand-drawn maze before asking questions.'],
        ];

        foreach ($curses as $i => [$name, $cost, $description]) {
            Curse::updateOrCreate(
                ['name' => $name],
                [
                    'cost' => $cost,
                    'description' => $description,
                    'is_custom' => false,
                    'is_active' => true,
                    'sort' => $i + 1,
                ],
            );
        }
    }
}
