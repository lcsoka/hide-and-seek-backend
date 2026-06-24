<?php

use App\Game\Modes\HideAndSeek\HideAndSeekMode;

return [
    'default_mode' => 'hide_and_seek',

    /*
     | Registered game modes, keyed by GameMode::key(). Adding a mode = one class
     | + one line here (no engine changes).
     */
    'modes' => [
        'hide_and_seek' => HideAndSeekMode::class,
    ],

    /*
     | Selectable Hungarian play cities (center coordinates). The map size (GameSize)
     | sets the play radius around the chosen city's center.
     */
    'cities' => [
        'budapest' => ['name' => 'Budapest', 'lat' => 47.4979, 'lng' => 19.0402],
        'debrecen' => ['name' => 'Debrecen', 'lat' => 47.5316, 'lng' => 21.6273],
        'szeged' => ['name' => 'Szeged', 'lat' => 46.2530, 'lng' => 20.1414],
        'miskolc' => ['name' => 'Miskolc', 'lat' => 48.1035, 'lng' => 20.7784],
        'pecs' => ['name' => 'Pécs', 'lat' => 46.0727, 'lng' => 18.2323],
        'gyor' => ['name' => 'Győr', 'lat' => 47.6875, 'lng' => 17.6504],
        'nyiregyhaza' => ['name' => 'Nyíregyháza', 'lat' => 47.9554, 'lng' => 21.7167],
        'kecskemet' => ['name' => 'Kecskemét', 'lat' => 46.8964, 'lng' => 19.6897],
        'szekesfehervar' => ['name' => 'Székesfehérvár', 'lat' => 47.1860, 'lng' => 18.4221],
        'szombathely' => ['name' => 'Szombathely', 'lat' => 47.2307, 'lng' => 16.6218],
    ],

    /*
     | Abandoned-game cleanup (see App\Console\Commands\PruneAbandonedSessions,
     | scheduled in routes/console.php).
     */
    'abandon' => [
        'lobby_idle_minutes' => 120,   // never-started session sitting in the lobby
        'active_idle_minutes' => 360,  // in-progress session with no player activity
        'retention_days' => 30,        // delete finished/abandoned sessions after this
    ],
];
