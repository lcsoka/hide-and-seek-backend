<?php

namespace Database\Seeders;

use App\Models\City;
use Illuminate\Database\Seeder;

/**
 * The playable Hungarian cities. Migrated out of config/game.php so admins can manage them
 * (cover photo, play size, and the transit modes that actually exist in each). Transit keys
 * use the mode enum: `light_rail` = HÉV, `rail` = train (available everywhere via stations).
 */
class CitySeeder extends Seeder
{
    public function run(): void
    {
        $sort = 0;
        foreach ($this->cities() as $city) {
            City::updateOrCreate(
                ['key' => $city['key']],
                array_merge($city, ['is_active' => true, 'sort' => ++$sort]),
            );
        }
    }

    /** @return array<int, array<string, mixed>> */
    private function cities(): array
    {
        return [
            ['key' => 'budapest', 'name' => 'Budapest', 'lat' => 47.4979, 'lng' => 19.0402, 'default_size' => 'medium', 'available_modes' => ['metro', 'light_rail', 'tram', 'trolleybus', 'bus', 'rail']],
            ['key' => 'debrecen', 'name' => 'Debrecen', 'lat' => 47.5316, 'lng' => 21.6273, 'default_size' => 'small', 'available_modes' => ['tram', 'trolleybus', 'bus', 'rail']],
            ['key' => 'szeged', 'name' => 'Szeged', 'lat' => 46.2530, 'lng' => 20.1414, 'default_size' => 'small', 'available_modes' => ['tram', 'trolleybus', 'bus', 'rail']],
            ['key' => 'miskolc', 'name' => 'Miskolc', 'lat' => 48.1035, 'lng' => 20.7784, 'default_size' => 'small', 'available_modes' => ['tram', 'bus', 'rail']],
            ['key' => 'pecs', 'name' => 'Pécs', 'lat' => 46.0727, 'lng' => 18.2323, 'default_size' => 'small', 'available_modes' => ['bus', 'rail']],
            ['key' => 'gyor', 'name' => 'Győr', 'lat' => 47.6875, 'lng' => 17.6504, 'default_size' => 'small', 'available_modes' => ['bus', 'rail']],
            ['key' => 'nyiregyhaza', 'name' => 'Nyíregyháza', 'lat' => 47.9554, 'lng' => 21.7167, 'default_size' => 'small', 'available_modes' => ['bus', 'rail']],
            ['key' => 'kecskemet', 'name' => 'Kecskemét', 'lat' => 46.8964, 'lng' => 19.6897, 'default_size' => 'small', 'available_modes' => ['bus', 'rail']],
            ['key' => 'szekesfehervar', 'name' => 'Székesfehérvár', 'lat' => 47.1860, 'lng' => 18.4221, 'default_size' => 'small', 'available_modes' => ['bus', 'rail']],
            ['key' => 'szombathely', 'name' => 'Szombathely', 'lat' => 47.2307, 'lng' => 16.6218, 'default_size' => 'small', 'available_modes' => ['bus', 'rail']],
        ];
    }
}
