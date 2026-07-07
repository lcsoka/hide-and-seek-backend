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
        'user_agent' => env('OVERPASS_USER_AGENT', 'HideAndSeek/1.0 (+https://hide-and-seek.test)'),
        'search_radius_m' => 50_000,
        'features' => [
            // Airport is TIERED (priority list of tiers; the first tier with any hit within range
            // wins). Tier 1 = a real, recognisable airport (scheduled service / classified type),
            // so "nearest airport" is Ferihegy in Budapest — not a nearby glider strip. Tier 2 =
            // any registered aerodrome (ICAO), a fallback so cities without a real airport (Szeged,
            // Miskolc, Kecskemét…) can still answer. Each tier is a list of unioned filters.
            // NB: keys with a colon (aerodrome:type) MUST be quoted or Overpass rejects the query.
            'airport' => [
                ['["aeroway"="aerodrome"]["iata"]', '["aeroway"="aerodrome"]["aerodrome:type"~"international|regional|public"]'],
                ['["aeroway"="aerodrome"]["icao"]'],
            ],
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
        // Transit-station feature keys whose OSM entities come in directional pairs (one platform
        // node per travel direction). Same-named entities within `station_dedup_m` of each other
        // collapse into ONE station, so the hiding-zone carve + matching questions don't treat the
        // two platforms of a single stop as two stations. POIs (museums, parks…) are left alone.
        'station_types' => ['rail_station', 'tram_stop', 'subway_station', 'bus_stop'],
        'station_dedup_m' => 90,
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

    // Max cards the hider may hold. The 'draw_1_expand_1' powerup raises it by 1.
    // The draw deck itself (curses + powerups + time-bonuses, with per-card copy counts)
    // now lives in the `cards` table and is built by HideAndSeekMode::deckPool().
    'hand_limit' => 6,

    /*
     | Abandoned-game cleanup (see App\Console\Commands\PruneAbandonedSessions,
     | scheduled in routes/console.php).
     */
    'abandon' => [
        'lobby_idle_minutes' => 120,   // never-started session sitting in the lobby
        'active_idle_minutes' => 360,  // in-progress session with no player activity
        'retention_days' => 30,        // delete finished/abandoned sessions after this
        'guest_retention_days' => 7,   // delete guest users (no email) with no live session after this
    ],
];
