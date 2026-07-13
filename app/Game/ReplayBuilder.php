<?php

namespace App\Game;

use App\Game\Geo\MapDataSource;
use App\Models\ActionLog;
use App\Models\Card;
use App\Models\PlayerPosition;
use App\Models\Session;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Assembles everything needed to replay a finished game on a map + timeline: per-player position
 * tracks (from player_positions), the merged event stream (actions + questions + curses), the
 * question snapshots (ask position, radius/feature, answer) and the hiding zone.
 */
class ReplayBuilder
{
    public function __construct(private readonly GameStatePresenter $presenter) {}

    public function build(Session $session): array
    {
        $session->loadMissing('players.user');
        $names = $session->players->pluck('display_name', 'id');

        $tracks = PlayerPosition::query()
            ->where('session_id', $session->id)
            ->orderBy('recorded_at')
            ->get(['player_id', 'lat', 'lng', 'recorded_at'])
            ->groupBy('player_id')
            ->map(fn (Collection $g) => $g->map(fn (PlayerPosition $p) => [$p->recorded_at->timestamp, (float) $p->lat, (float) $p->lng])->all());

        $players = $session->players->map(fn ($p) => [
            'id' => $p->id,
            'name' => $p->display_name,
            'role' => $p->role,
            'color' => $p->role === 'hider' ? '#e11d48' : $this->colorFor($p->id),
            'avatar' => $p->user?->avatar_thumb ?? $p->user?->avatar,
            'track' => $tracks[$p->id] ?? [],
            'last' => $p->last_lat !== null ? [(float) $p->last_lat, (float) $p->last_lng] : null,
        ])->values()->all();

        // state_data only holds the current round; merge in the archived rounds so the replay covers the
        // whole game (every round's questions + curses), not just the last one.
        $questions = collect($this->presenter->questionsFrom($this->acrossRounds($session, 'questions')))
            ->map(function (array $q): array {
                $ask = $q['ask'] ?? [];
                $answer = $q['answer'] ?? null;

                return [
                    'seq' => $q['seq'] ?? null,
                    'at' => $q['asked_at'] ?? null,
                    'by' => $q['asked_by'] ?? null,
                    'category' => $q['category'] ?? null,
                    'answer' => is_array($answer) ? ($answer['answer'] ?? null) : $answer,
                    // A photo/video question answer is the hider's clue — surface it in the timeline.
                    'photo' => is_array($answer) ? ($answer['photo_url'] ?? null) : null,
                    'ask' => isset($ask['lat'], $ask['lng'])
                        ? ['lat' => (float) $ask['lat'], 'lng' => (float) $ask['lng'], 'radius_m' => $ask['radius_m'] ?? null, 'feature' => $ask['feature'] ?? null]
                        : null,
                    'end' => isset($q['end']['lat'], $q['end']['lng'])
                        ? ['lat' => (float) $q['end']['lat'], 'lng' => (float) $q['end']['lng']]
                        : null,
                    // OSM geometry so the replay can cut matching/tentacles/measuring like the web app:
                    // the candidate POIs (for the Voronoi cell) + the seeker's reference/matched place.
                    'geo' => $this->questionGeo($q['category'] ?? null, $ask, is_array($answer) ? $answer : []),
                ];
            })
            ->filter(fn ($q) => $q['at'] !== null)
            ->sortBy('at')
            ->values()->all();

        $rawCurses = $this->acrossRounds($session, 'curses_played');
        $curseNames = Card::whereIn('id', collect($rawCurses)->pluck('curse_id')->filter()->unique()->all())->pluck('name', 'id');
        $curses = collect($rawCurses)
            ->map(fn (array $c) => [
                'at' => $c['at'] ?? null,
                'by' => $c['by'] ?? null,
                'name' => $curseNames[$c['curse_id'] ?? null] ?? 'Curse',
                // The hider's cast photo/video + the seeker's completion proof, whichever exist.
                'media' => array_values(array_filter([$c['hint_photo_url'] ?? null, $c['proof_url'] ?? null])),
            ])
            ->filter(fn ($c) => $c['at'] !== null)
            ->sortBy('at')
            ->values()->all();

        $events = $this->buildEvents($session, $names, $questions, $curses);

        // Time bounds span every timestamped thing (events + track samples).
        $times = collect($events)->pluck('at');
        foreach ($tracks as $track) {
            foreach ($track as $sample) {
                $times->push($sample[0]);
            }
        }
        $t0 = $times->min() ?? $session->created_at?->timestamp ?? 0;
        $t1 = $times->max() ?? $t0 + 1;
        if ($t1 <= $t0) {
            $t1 = $t0 + 1;
        }

        $zone = $session->state_data['hiding_zone'] ?? null;
        $city = $session->config['city'] ?? null;

        // The zone centre is stored as {lat,lng} by a real game but seeds/older rows may use [lat,lng].
        $center = is_array($zone['center'] ?? null) ? $zone['center'] : null;
        $zoneLatLng = match (true) {
            isset($center['lat'], $center['lng']) => [(float) $center['lat'], (float) $center['lng']],
            isset($center[0], $center[1]) => [(float) $center[0], (float) $center[1]],
            default => null,
        };

        // Each round the hider picks a fresh station, so the zone moves over the game. state_data only
        // keeps the current round's zone, so reconstruct the whole timeline from the choose_station log —
        // otherwise the replay would draw the last round's zone while the hider sits in an earlier one.
        $zoneRadius = (float) ($session->config['hiding_zone_radius_m'] ?? ($zone['radius_m'] ?? 500));
        // Carve the zone by the transit stops of the game's modes (metro/tram), which is what actually bounds
        // it — and which are far denser than plain rail stations, so urban zones get a meaningful cell.
        $stationTypes = [];
        if ((string) ($session->config['hiding_zone_rule'] ?? config('game.hiding_zone.default_rule', 'circle')) === 'nearest') {
            $modeMap = ['metro' => 'subway_station', 'tram' => 'tram_stop', 'rail' => 'rail_station', 'train' => 'rail_station', 'bus' => 'bus_stop'];
            $modes = (array) ($session->config['transit_modes'] ?? config('game.hiding_zone.default_modes', ['metro', 'tram']));
            $stationTypes = array_values(array_unique(array_filter(array_map(fn ($m) => $modeMap[$m] ?? null, $modes))));
            if (empty($stationTypes)) {
                $stationTypes = [(string) config('game.hiding_zone.station_feature', 'rail_station')];
            }
        }
        $zones = ActionLog::query()
            ->where('session_id', $session->id)
            ->where('type', 'choose_station')
            ->orderBy('created_at')
            ->get(['payload', 'created_at'])
            ->map(fn (ActionLog $log) => isset($log->payload['lat'], $log->payload['lng'])
                ? [
                    'at' => $log->created_at?->timestamp,
                    'lat' => (float) $log->payload['lat'],
                    'lng' => (float) $log->payload['lng'],
                    'radius_m' => $zoneRadius,
                    // Nearby transit stops so the replay can draw the real carved zone (the chosen stop's
                    // cell, cut where another stop becomes closer), mirroring how the game client draws it.
                    'stations' => $this->zoneStations($stationTypes, (float) $log->payload['lat'], (float) $log->payload['lng'], $zoneRadius),
                ]
                : null)
            ->filter()
            ->values()->all();

        // Round windows: the game splits at each advance_round, so round r spans [bounds[r-1], bounds[r]].
        $advanceAts = ActionLog::query()
            ->where('session_id', $session->id)
            ->where('type', 'advance_round')
            ->orderBy('created_at')
            ->pluck('created_at')
            ->map(fn ($d) => $d?->timestamp)
            ->filter()
            ->values()
            ->all();
        $bounds = array_merge([$t0], $advanceAts, [$t1]);
        $rounds = [];
        foreach ($bounds as $i => $start) {
            $end = $bounds[$i + 1] ?? null;
            if ($end === null || $end - $start <= 1) {
                continue; // skip the final advance_round→finished boundary, which leaves a zero-width window
            }
            $rounds[] = ['round' => count($rounds) + 1, 'start' => $start, 'end' => $end];
        }

        return [
            'code' => $session->join_code,
            'city' => $city,
            't0' => $t0,
            't1' => $t1,
            'players' => $players,
            'questions' => $questions,
            'curses' => $curses,
            'events' => $events,
            'zone' => $zoneLatLng
                ? ['lat' => $zoneLatLng[0], 'lng' => $zoneLatLng[1], 'radius_m' => $zone['radius_m'] ?? 500]
                : null,
            'zones' => $zones, // per-round hiding zones, active from each `at` until the next
            'rounds' => $rounds, // [{round, start, end}] so the replay can offer a per-round switcher

            // The deduction's starting candidate: the city's real admin boundary (like the web app),
            // with a plain circle as the fallback if the boundary can't be fetched.
            'playArea' => (is_array($city) && isset($city['lat'], $city['lng']))
                ? ['lat' => (float) $city['lat'], 'lng' => (float) $city['lng'], 'radiusKm' => (float) ($session->config['play_radius_km'] ?? 15)]
                : null,
            'playAreaGeo' => $this->cityBoundary($city),
        ];
    }

    /**
     * Flatten one round-scoped state_data key across every round: the archived rounds (rounds_log)
     * followed by the current, live round. Used for questions + curses so replays span the whole game.
     *
     * @return array<int, array<string, mixed>>
     */
    private function acrossRounds(Session $session, string $key): array
    {
        $out = [];
        foreach ($session->state_data['rounds_log'] ?? [] as $round) {
            foreach ($round[$key] ?? [] as $item) {
                $out[] = $item;
            }
        }
        foreach ($session->state_data[$key] ?? [] as $item) {
            $out[] = $item;
        }

        return $out;
    }

    /**
     * OSM geometry for a matching/tentacles/measuring question, so the replay can reconstruct its
     * cut the way the web app does: the candidate POIs of the question's feature type (whose Voronoi
     * split IS the cut) + the seeker's reference/matched place (from the answer). Best-effort +
     * cached; returns null for categories/answers that don't need it (radar, thermometer, admin zone).
     *
     * @param  array<string, mixed>  $ask
     * @param  array<string, mixed>  $answer
     * @return array{pois: array<int, array{0: float, 1: float, 2: ?string}>, ref: ?array{lat: float, lng: float, name: ?string}}|null
     */
    private function questionGeo(?string $category, array $ask, array $answer): ?array
    {
        $feature = $ask['feature'] ?? null;
        if (! in_array($category, ['tentacles', 'matching', 'measuring'], true) || ! is_string($feature) || ! isset($ask['lat'], $ask['lng'])) {
            return null; // admin-zone matching / border measuring carry no point feature (need boundary geometry)
        }
        $lat = (float) $ask['lat'];
        $lng = (float) $ask['lng'];
        $ref = isset($answer['feature_lat'], $answer['feature_lng'])
            ? ['lat' => (float) $answer['feature_lat'], 'lng' => (float) $answer['feature_lng'], 'name' => $answer['feature_name'] ?? null]
            : null;

        // Measuring is a single circle around the reference — no POI set needed.
        if ($category === 'measuring') {
            return ['pois' => [], 'ref' => $ref];
        }

        // Tentacles: candidates within the radius. Matching: a wide area for the Voronoi — 80 km to
        // match the web app's search, so both stacks build the SAME cell.
        $radiusM = $category === 'tentacles' ? (float) ($ask['radius_m'] ?? 1609) : 80000.0;
        $pois = [];
        try {
            $features = app(MapDataSource::class)->within($feature, $lat, $lng, $radiusM);
            // Nearest-first (lng scaled by cos lat) so the cap keeps the reference cell's immediate
            // neighbours — they alone decide its shape; far cells don't matter.
            $k = cos(deg2rad($lat)) ?: 1.0;
            usort($features, function ($a, $b) use ($lat, $lng, $k) {
                $da = ($a->lat - $lat) ** 2 + (($a->lng - $lng) * $k) ** 2;
                $db = ($b->lat - $lat) ** 2 + (($b->lng - $lng) * $k) ** 2;

                return $da <=> $db;
            });
            foreach (array_slice($features, 0, 400) as $f) {
                $pois[] = [round($f->lat, 6), round($f->lng, 6), $f->name];
            }
        } catch (\Throwable) {
            // best-effort: no POIs → the blade falls back gracefully (whole circle / no cut)
        }

        return ['pois' => $pois, 'ref' => $ref];
    }

    /**
     * Nearby transit stops around a hiding zone (chosen stop + neighbours whose bisectors could carve it),
     * as [[lat,lng],…]. Best-effort + cached; the map source at replay time supplies the real OSM stops.
     *
     * @param  array<int, string>  $types
     * @return array<int, array{0: float, 1: float}>
     */
    private function zoneStations(array $types, float $lat, float $lng, float $radiusM): array
    {
        if (empty($types)) {
            return [];
        }

        return Cache::remember(sprintf('zone_stations:%s:%.4f,%.4f:%d', implode(',', $types), $lat, $lng, (int) $radiusM), now()->addDays(7), function () use ($types, $lat, $lng, $radiusM) {
            try {
                // A neighbouring stop only cuts the radius circle if it's within ~2× the radius of the centre.
                $source = app(MapDataSource::class);
                $out = [];
                foreach ($types as $type) {
                    foreach ($source->within($type, $lat, $lng, $radiusM * 2.5) as $f) {
                        $out[] = [round($f->lat, 6), round($f->lng, 6)];
                    }
                }

                return $out;
            } catch (\Throwable) {
                return [];
            }
        });
    }

    /** The city's administrative boundary as GeoJSON (Polygon/MultiPolygon), fetched once from Nominatim and cached. */
    private function cityBoundary(mixed $city): ?array
    {
        if (! is_array($city) || empty($city['name'])) {
            return null;
        }

        return Cache::remember('city_boundary:'.strtolower((string) $city['name']), now()->addDays(30), function () use ($city) {
            try {
                $results = Http::withHeaders(['User-Agent' => config('game.overpass.user_agent', 'HideAndSeek/1.0')])
                    ->timeout(15)
                    ->get('https://nominatim.openstreetmap.org/search', [
                        'q' => $city['name'].', Hungary',
                        'format' => 'jsonv2',
                        'polygon_geojson' => 1,
                        'limit' => 3,
                    ])
                    ->json();

                foreach ((array) $results as $r) {
                    $geo = $r['geojson'] ?? null;
                    if (is_array($geo) && in_array($geo['type'] ?? '', ['Polygon', 'MultiPolygon'], true)) {
                        return $geo;
                    }
                }
            } catch (\Throwable) {
                // fall back to the circle
            }

            return null;
        });
    }

    /** Merge the action log (minus ask/answer/curse, which the question + curse rows carry) with the questions and curses into one sorted feed. */
    private function buildEvents(Session $session, Collection $names, array $questions, array $curses): array
    {
        $events = [];

        // ask/answer/curse are carried by the question + curse rows; location:observed is a
        // high-volume position ping that would flood the timeline with noise.
        $skip = ['ask_question', 'answer_question', 'amend_answer', 'play_curse', 'location:observed'];
        foreach (ActionLog::query()->where('session_id', $session->id)->orderBy('created_at')->get(['type', 'player_id', 'created_at']) as $log) {
            if (in_array($log->type, $skip, true)) {
                continue;
            }
            $events[] = [
                'at' => $log->created_at?->timestamp,
                'kind' => 'action',
                'label' => ucfirst(str_replace('_', ' ', $log->type)),
                'by' => $names[$log->player_id] ?? null,
                'media' => [],
            ];
        }

        foreach ($questions as $q) {
            $events[] = [
                'at' => $q['at'],
                'kind' => 'ask',
                'label' => ucfirst((string) $q['category']).' question'.($q['answer'] ? ' → '.str_replace('_', ' ', (string) $q['answer']) : ''),
                'by' => $names[$q['by']] ?? null,
                'media' => ! empty($q['photo']) ? [$q['photo']] : [],
            ];
        }

        foreach ($curses as $c) {
            $events[] = [
                'at' => $c['at'],
                'kind' => 'curse',
                'label' => 'Curse: '.$c['name'],
                'by' => $names[$c['by']] ?? null,
                'media' => $c['media'] ?? [],
                // A played curse card, shown as a coloured chip in the timeline.
                'card' => ['name' => $c['name'], 'color' => '#7c3aed'],
            ];
        }

        return collect($events)->filter(fn ($e) => $e['at'] !== null)->sortBy('at')->values()->all();
    }

    /** A stable, pleasant colour from a seed — mirrors the web colorFor() so tracks match the game map. */
    private function colorFor(string $seed): string
    {
        $hash = 0;
        for ($i = 0; $i < strlen($seed); $i++) {
            $hash = ($hash * 31 + ord($seed[$i])) & 0xFFFFFFFF;
        }

        return 'hsl('.($hash % 360).' 62% 45%)';
    }
}
