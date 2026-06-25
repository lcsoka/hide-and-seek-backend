<?php

namespace Database\Seeders;

use App\Models\Curse;
use Illuminate\Database\Seeder;

class CurseSeeder extends Seeder
{
    /**
     * Official Jet Lag: Hide + Seek curses (bilingual en/hu).
     * Source: https://jetlag.denull.ru/en/rules/curses/ (and the Jet Lag wiki).
     * Hungarian is a first pass — refine wording in the admin (locale-aware).
     */
    public function run(): void
    {
        $parameters = $this->parameters();

        foreach ($this->curses() as $i => $curse) {
            Curse::updateOrCreate(
                ['key' => $curse['key']],
                [
                    'name' => $curse['name'],
                    'cost' => $curse['cost'],
                    'description' => $curse['description'],
                    'parameters' => $parameters[$curse['key']] ?? null,
                    'is_custom' => false,
                    'is_active' => true,
                    'sort' => $i + 1,
                ],
            );
        }
    }

    /**
     * Lifecycle metadata per curse: `requires_proof` (seekers must upload a photo to
     * clear it) and/or `duration_s` (auto-expires after this many seconds). Derived
     * from the official cost/effect; tune in the admin. Curses absent here are plain
     * rule-effects with no timer or proof.
     *
     * @return array<string, array<string, mixed>>
     */
    private function parameters(): array
    {
        return [
            // "Photograph / film X" curses — cleared with a photo.
            'the_luxury_car' => ['requires_proof' => true],
            'the_zoologist' => ['requires_proof' => true],
            'the_bird_guide' => ['requires_proof' => true],
            'the_cairn' => ['requires_proof' => true],
            'the_ransom_note' => ['requires_proof' => true],
            'the_labyrinth' => ['requires_proof' => true],
            'the_mediocre_travel_agent' => ['requires_proof' => true],
            // Timed effects (representative midpoints of the official ranges).
            'the_jammed_door' => ['duration_s' => 3600, 'dice' => ['count' => 2, 'sides' => 6, 'target' => 7]], // roll 7+ to enter
            'the_gamblers_feet' => ['duration_s' => 1800, 'dice' => ['count' => 1, 'sides' => 6]],              // roll before each step
            'the_right_turn' => ['duration_s' => 1800],      // 20–60 min
            // Dice-only effect.
            'the_endless_tumble' => ['dice' => ['count' => 1, 'sides' => 6, 'target' => 5]], // roll 5–6 to proceed
        ];
    }

    private function curses(): array
    {
        return [
            ['key' => 'the_luxury_car', 'name' => ['en' => 'The Luxury Car', 'hu' => 'A luxusautó'], 'cost' => ['en' => 'Photo of a car', 'hu' => 'Fotó egy autóról'], 'description' => ['en' => 'Seekers must photograph a more expensive car before asking questions.', 'hu' => 'A kérdezés előtt a keresőknek le kell fotózniuk egy drágább autót.']],
            ['key' => 'the_bridge_troll', 'name' => ['en' => 'The Bridge Troll', 'hu' => 'A hídi manó'], 'cost' => ['en' => 'Distance (varies by game size)', 'hu' => 'Távolság (játékmérettől függ)'], 'description' => ['en' => 'Seekers must ask their next question from under a bridge.', 'hu' => 'A keresőknek a következő kérdést egy híd alól kell feltenniük.']],
            ['key' => 'the_drained_brain', 'name' => ['en' => 'The Drained Brain', 'hu' => 'A kiürült elme'], 'cost' => ['en' => 'Discard entire hand', 'hu' => 'A teljes kéz eldobása'], 'description' => ['en' => 'Choose three questions in different categories; the seekers cannot ask those questions.', 'hu' => 'Válassz három kérdést különböző kategóriákból; a keresők nem tehetik fel ezeket.']],
            ['key' => 'water_weight', 'name' => ['en' => 'Water Weight', 'hu' => 'Vízteher'], 'cost' => ['en' => 'Within 300m of a body of water', 'hu' => 'Víztesttől 300 m-en belül'], 'description' => ['en' => 'Seekers must carry 2 litres of liquid per person for the remainder of the run.', 'hu' => 'A keresőknek a hátralévő futam során fejenként 2 liter folyadékot kell vinniük.']],
            ['key' => 'the_zoologist', 'name' => ['en' => 'The Zoologist', 'hu' => 'A zoológus'], 'cost' => ['en' => 'Photo of an animal', 'hu' => 'Fotó egy állatról'], 'description' => ['en' => 'Seekers must photograph a wild animal in the same category before asking questions.', 'hu' => 'A kérdezés előtt a keresőknek le kell fotózniuk egy vadállatot ugyanabban a kategóriában.']],
            ['key' => 'the_egg_partner', 'name' => ['en' => 'The Egg Partner', 'hu' => 'A tojástárs'], 'cost' => ['en' => 'Discard 2 cards', 'hu' => '2 kártya eldobása'], 'description' => ['en' => 'Seekers must acquire and protect an egg as a team member; bonus to the hider if it is abandoned.', 'hu' => 'A keresőknek be kell szerezniük és csapattagként óvniuk egy tojást; ha elhagyják, a bújó bónuszt kap.']],
            ['key' => 'the_jammed_door', 'name' => ['en' => 'The Jammed Door', 'hu' => 'A beragadt ajtó'], 'cost' => ['en' => 'Discard 2 cards', 'hu' => '2 kártya eldobása'], 'description' => ['en' => 'Seekers must roll a 7+ to enter buildings for the next 0.5–3 hours.', 'hu' => 'A következő 0,5–3 órában a keresőknek 7+ dobás kell az épületekbe való belépéshez.']],
            ['key' => 'spotty_memory', 'name' => ['en' => 'Spotty Memory', 'hu' => 'Foltos memória'], 'cost' => ['en' => 'Discard a time-bonus card', 'hu' => 'Időbónusz kártya eldobása'], 'description' => ['en' => 'One random question category is disabled and changes after each question asked.', 'hu' => 'Egy véletlenszerű kérdéskategória letiltva; minden feltett kérdés után változik.']],
            ['key' => 'the_bird_guide', 'name' => ['en' => 'The Bird Guide', 'hu' => 'A madárkalauz'], 'cost' => ['en' => 'Film a bird', 'hu' => 'Madár filmezése'], 'description' => ['en' => "Seekers must film a bird for a duration equal to or longer than the hider's footage.", 'hu' => 'A keresőknek legalább annyi ideig kell madarat filmezniük, mint a bújó felvétele.']],
            ['key' => 'the_unguided_tourist', 'name' => ['en' => 'The Unguided Tourist', 'hu' => 'A vezető nélküli turista'], 'cost' => ['en' => 'Seekers must be outside', 'hu' => 'A keresők legyenek kint'], 'description' => ['en' => 'Seekers must locate a Street View image in real life before transit or questions.', 'hu' => 'A keresőknek a valóságban meg kell találniuk egy Street View képet a közlekedés/kérdés előtt.']],
            ['key' => 'the_ransom_note', 'name' => ['en' => 'The Ransom Note', 'hu' => 'A zsarolólevél'], 'cost' => ['en' => 'Spell out "Ransom Note" physically', 'hu' => 'A „zsarolólevél” fizikai kirakása'], 'description' => ['en' => 'The next question must be composed from cut-out printed letters or words.', 'hu' => 'A következő kérdést kivágott, nyomtatott betűkből vagy szavakból kell összeállítani.']],
            ['key' => 'the_mediocre_travel_agent', 'name' => ['en' => 'The Mediocre Travel Agent', 'hu' => 'A közepes utazási iroda'], 'cost' => ['en' => 'Destination further than current location', 'hu' => 'A jelenleginél távolabbi célpont'], 'description' => ['en' => 'Seekers must visit a nearby location, spend time there, take photos, and get a souvenir.', 'hu' => 'A keresőknek fel kell keresniük egy közeli helyet, időt tölteni ott, fotózni és szuvenírt szerezni.']],
            ['key' => 'the_impressionable_consumer', 'name' => ['en' => 'The Impressionable Consumer', 'hu' => 'A befolyásolható fogyasztó'], 'cost' => ['en' => 'Next question is free', 'hu' => 'A következő kérdés ingyenes'], 'description' => ['en' => 'Seekers must visit a location or buy a product advertised 30m+ away.', 'hu' => 'A keresőknek fel kell keresniük egy 30 m-nél távolabb hirdetett helyet vagy terméket.']],
            ['key' => 'the_u_turn', 'name' => ['en' => 'The U-Turn', 'hu' => 'A visszafordulás'], 'cost' => ['en' => 'Seekers heading the wrong direction', 'hu' => 'A keresők rossz irányba tartanak'], 'description' => ['en' => 'Seekers must disembark at the next station if alternate transit is available.', 'hu' => 'Ha van alternatív közlekedés, a keresőknek a következő megállónál le kell szállniuk.']],
            ['key' => 'the_cairn', 'name' => ['en' => 'The Cairn', 'hu' => 'A kőrakás'], 'cost' => ['en' => 'Build a rock tower', 'hu' => 'Kőtorony építése'], 'description' => ['en' => 'Seekers must build a matching-height rock tower before asking questions.', 'hu' => 'A kérdezés előtt a keresőknek azonos magasságú kőtornyot kell építeniük.']],
            ['key' => 'the_distant_cuisine', 'name' => ['en' => 'The Distant Cuisine', 'hu' => 'A távoli konyha'], 'cost' => ['en' => 'Must be at a restaurant', 'hu' => 'Étteremben kell lenni'], 'description' => ['en' => 'Seekers must visit a restaurant serving equally-distant foreign cuisine.', 'hu' => 'A keresőknek fel kell keresniük egy ugyanolyan távoli külföldi konyhát kínáló éttermet.']],
            ['key' => 'the_lemon_phylactery', 'name' => ['en' => 'The Lemon Phylactery', 'hu' => 'A citrom-amulett'], 'cost' => ['en' => 'Discard a powerup card', 'hu' => 'Erősítő kártya eldobása'], 'description' => ['en' => 'Each seeker must affix a real lemon to their clothes; bonus to the hider if a lemon falls.', 'hu' => 'Minden keresőnek egy valódi citromot kell a ruhájára rögzítenie; ha leesik, a bújó bónuszt kap.']],
            ['key' => 'the_gamblers_feet', 'name' => ['en' => "The Gambler's Feet", 'hu' => 'A szerencsejátékos lábai'], 'cost' => ['en' => 'Roll a die; no effect if even', 'hu' => 'Dobókocka; páros esetén nincs hatás'], 'description' => ['en' => 'For the next 20–60 minutes seekers must roll a die before steps, moving that many.', 'hu' => 'A következő 20–60 percben a keresőknek lépés előtt dobniuk kell, és annyit léphetnek.']],
            ['key' => 'the_hidden_hangman', 'name' => ['en' => 'The Hidden Hangman', 'hu' => 'A rejtett akasztófa'], 'cost' => ['en' => 'Discard 2 cards', 'hu' => '2 kártya eldobása'], 'description' => ['en' => 'Seekers must play hangman against the hider before asking questions or transit.', 'hu' => 'A kérdezés/közlekedés előtt a keresőknek akasztófát kell játszaniuk a bújó ellen.']],
            ['key' => 'the_endless_tumble', 'name' => ['en' => 'The Endless Tumble', 'hu' => 'A végtelen gurulás'], 'cost' => ['en' => 'Roll a die; no effect if 5–6', 'hu' => 'Dobókocka; 5–6 esetén nincs hatás'], 'description' => ['en' => 'Seekers must roll a die 30m+ and land 5–6 before asking questions.', 'hu' => 'A kérdezés előtt a keresőknek 30 m-re kell gurítaniuk a kockát, és 5–6-ot dobniuk.']],
            ['key' => 'the_right_turn', 'name' => ['en' => 'The Right Turn', 'hu' => 'A jobbra fordulás'], 'cost' => ['en' => 'Discard 1 card', 'hu' => '1 kártya eldobása'], 'description' => ['en' => 'For the next 20–60 minutes seekers can only turn right at intersections.', 'hu' => 'A következő 20–60 percben a keresők kereszteződésekben csak jobbra fordulhatnak.']],
            ['key' => 'the_urban_explorer', 'name' => ['en' => 'The Urban Explorer', 'hu' => 'A városfelfedező'], 'cost' => ['en' => 'Discard 2 cards', 'hu' => '2 kártya eldobása'], 'description' => ['en' => 'Seekers cannot ask questions while on transit or in train stations.', 'hu' => 'A keresők nem kérdezhetnek közlekedés közben vagy vasútállomásokon.']],
            ['key' => 'the_overflowing_chalice', 'name' => ['en' => 'The Overflowing Chalice', 'hu' => 'A túlcsorduló kehely'], 'cost' => ['en' => 'Discard 1 card', 'hu' => '1 kártya eldobása'], 'description' => ['en' => 'For the next three questions, the hider may draw an additional card from the hider deck.', 'hu' => 'A következő három kérdésnél a bújó egy extra kártyát húzhat a bújó pakliból.']],
            ['key' => 'the_labyrinth', 'name' => ['en' => 'The Labyrinth', 'hu' => 'A labirintus'], 'cost' => ['en' => 'Draw a maze', 'hu' => 'Labirintus rajzolása'], 'description' => ['en' => 'Seekers must solve a hand-drawn maze before asking questions.', 'hu' => 'A kérdezés előtt a keresőknek meg kell oldaniuk egy kézzel rajzolt labirintust.']],
        ];
    }
}
