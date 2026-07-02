<?php

use App\Game\Modes\HideAndSeek\HideAndSeekMode;
use App\Game\Questions\MatchingEvaluator;
use App\Game\Questions\MeasuringEvaluator;
use App\Game\Questions\RadarEvaluator;
use App\Game\Questions\TentaclesEvaluator;
use App\Game\Questions\ThermometerEvaluator;

return [
    'default_mode' => 'hide_and_seek',

    // Emails allowed into the Filament admin panel (comma-separated in FILAMENT_ADMIN_EMAILS).
    // Empty = nobody (locked down by default); guests never have an email.
    'admin_emails' => array_values(array_filter(array_map(
        fn ($e) => strtolower(trim($e)),
        explode(',', (string) env('FILAMENT_ADMIN_EMAILS', '')),
    ))),

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
        // Tried in order; the public mirrors rate-limit, so a fallback keeps questions answerable.
        'endpoints' => [
            env('OVERPASS_ENDPOINT', 'https://overpass-api.de/api/interpreter'),
            'https://overpass.private.coffee/api/interpreter',
        ],
        // overpass-api.de rejects requests without a descriptive User-Agent (HTTP 406).
        'user_agent' => env('OVERPASS_USER_AGENT', 'Bujocska/1.0 (+https://hide-and-seek.test)'),
        'search_radius_m' => 50_000,
        'features' => [
            'airport' => 'aeroway=aerodrome',
            'rail_station' => 'railway=station',
            'tram_stop' => 'railway=tram_stop',
            'subway_station' => 'station=subway',
            'bus_stop' => 'highway=bus_stop',
            'museum' => 'tourism=museum',
            'park' => 'leisure=park',
            'hospital' => 'amenity=hospital',
            'library' => 'amenity=library',
            'zoo' => 'tourism=zoo',
            'aquarium' => 'tourism=aquarium',
            'amusement_park' => 'tourism=theme_park',
            'golf_course' => 'leisure=golf_course',
            'movie_theater' => 'amenity=cinema',
            // Lakes, ponds, reservoirs and river surfaces (the Danube etc. are mapped as
            // natural=water polygons); `out center` gives each one's centroid to measure to.
            'body_of_water' => 'natural=water',
            'mountain' => 'natural=peak',        // named summits (Buda hills, Mecsek, Bükk…)
            'consulate' => 'office=diplomatic',  // embassies + consulates (mostly Budapest)
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
     | Hider's hiding zone. `default_rule`: 'nearest' = official rule — the zone is the
     | radius around the chosen station carved at the perpendicular bisector toward every
     | neighbouring station, so the chosen station is always the nearest and NO other
     | station falls inside the zone. 'circle' = lenient variant (plain radius, other
     | stations may be inside) for casual play.
     */
    'hiding_zone' => [
        'default_rule' => 'nearest',
        'station_feature' => 'rail_station',
        // The carved zone (chosen stop is the nearest of all nearby transit stops) is drawn
        // CLIENT-side from stops it fetches via the cached /geo/overpass proxy — keeping the
        // choose-station action fast and off Overpass.
    ],

    /*
     | The hider's draw deck. Answering a question lets the hider draw `reward_draw`
     | cards and keep `reward_keep` (per the question). The deck mixes curse cards
     | (from the curses table) with time-bonus and powerup cards defined here.
     | Composition mirrors the official deck: 25 time-bonus, 21 powerup, 24 curse cards.
     */
    // Max cards the hider may hold. The 'draw_1_expand_1' powerup raises it by 1.
    'hand_limit' => 6,

    'hider_deck' => [
        // Minutes added to the hider's run time (official 5-tier set); `count` = copies.
        'time_bonuses' => [
            ['minutes' => 2, 'count' => 2],
            ['minutes' => 3, 'count' => 2],
            ['minutes' => 4, 'count' => 2],
            ['minutes' => 5, 'count' => 2],
            ['minutes' => 6, 'count' => 3],
            ['minutes' => 8, 'count' => 2],
            ['minutes' => 9, 'count' => 2],
            ['minutes' => 10, 'count' => 2],
            ['minutes' => 12, 'count' => 2],
            ['minutes' => 15, 'count' => 2],
            ['minutes' => 18, 'count' => 1],
            ['minutes' => 20, 'count' => 1],
            ['minutes' => 30, 'count' => 2],
        ],
        // Powerup cards (names/descriptions in lang/{locale}/cards.php). Official mix.
        'powerups' => [
            ['power' => 'randomize', 'count' => 4],
            ['power' => 'veto', 'count' => 4],
            ['power' => 'duplicate', 'count' => 2],
            ['power' => 'move', 'count' => 1],
            ['power' => 'discard_1_draw_2', 'count' => 4],
            ['power' => 'discard_2_draw_3', 'count' => 4],
            ['power' => 'draw_1_expand_1', 'count' => 2],
        ],
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
