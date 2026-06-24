<?php

use App\Game\Modes\HideAndSeek\HideAndSeekMode;
use App\Game\Questions\MatchingEvaluator;
use App\Game\Questions\MeasuringEvaluator;
use App\Game\Questions\RadarEvaluator;
use App\Game\Questions\TentaclesEvaluator;
use App\Game\Questions\ThermometerEvaluator;

return [
    'default_mode' => 'hide_and_seek',

    /*
     | Developer/debug API gate (see App\Http\Middleware\EnsureDebugAccess). Must be
     | OFF in production. When on, callers must also present the developer token.
     */
    'debug' => [
        'enabled' => env('GAME_DEBUG', false),
        'token' => env('GAME_DEBUG_TOKEN'),
    ],

    /*
     | Registered game modes, keyed by GameMode::key(). Adding a mode = one class
     | + one line here (no engine changes).
     */
    'modes' => [
        'hide_and_seek' => HideAndSeekMode::class,
    ],

    /*
     | Server-side question evaluators (one per category). Radar is pure geometry;
     | matching/measuring/tentacles use the MapDataSource (Overpass); thermometer
     | is deferred (resolved after the seeker moves).
     */
    'question_evaluators' => [
        RadarEvaluator::class,
        MatchingEvaluator::class,
        MeasuringEvaluator::class,
        TentaclesEvaluator::class,
        ThermometerEvaluator::class,
    ],

    /*
     | OpenStreetMap data via the Overpass API (the live MapDataSource backend).
     | `features` maps a question feature key to an OSM tag.
     */
    'overpass' => [
        'endpoint' => env('OVERPASS_ENDPOINT', 'https://overpass-api.de/api/interpreter'),
        'search_radius_m' => 50_000,
        'features' => [
            'airport' => 'aeroway=aerodrome',
            'rail_station' => 'railway=station',
            'museum' => 'tourism=museum',
            'park' => 'leisure=park',
            'hospital' => 'amenity=hospital',
            'library' => 'amenity=library',
            'zoo' => 'tourism=zoo',
            'aquarium' => 'tourism=aquarium',
            'amusement_park' => 'tourism=theme_park',
            'golf_course' => 'leisure=golf_course',
            'movie_theater' => 'amenity=cinema',
        ],
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
     | Hider's hiding zone. `default_rule`: 'circle' = official (simple radius around
     | the chosen station); 'nearest' = stricter variant where areas closer to another
     | station are carved out (chosen station must be the hider's nearest).
     */
    'hiding_zone' => [
        'default_rule' => 'circle',
        'station_feature' => 'rail_station',
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
