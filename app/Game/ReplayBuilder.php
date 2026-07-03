<?php

namespace App\Game;

use App\Models\ActionLog;
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
        $history = $this->presenter->history($session);
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
            'avatar' => $p->user?->avatar,
            'track' => $tracks[$p->id] ?? [],
            'last' => $p->last_lat !== null ? [(float) $p->last_lat, (float) $p->last_lng] : null,
        ])->values()->all();

        $questions = collect($history['questions'])
            ->map(function (array $q): array {
                $ask = $q['ask'] ?? [];
                $answer = $q['answer'] ?? null;

                return [
                    'seq' => $q['seq'] ?? null,
                    'at' => $q['asked_at'] ?? null,
                    'by' => $q['asked_by'] ?? null,
                    'category' => $q['category'] ?? null,
                    'answer' => is_array($answer) ? ($answer['answer'] ?? null) : $answer,
                    'ask' => isset($ask['lat'], $ask['lng'])
                        ? ['lat' => (float) $ask['lat'], 'lng' => (float) $ask['lng'], 'radius_m' => $ask['radius_m'] ?? null, 'feature' => $ask['feature'] ?? null]
                        : null,
                    'end' => isset($q['end']['lat'], $q['end']['lng'])
                        ? ['lat' => (float) $q['end']['lat'], 'lng' => (float) $q['end']['lng']]
                        : null,
                ];
            })
            ->filter(fn ($q) => $q['at'] !== null)
            ->values()->all();

        $curses = collect($history['curses'])
            ->map(fn (array $c) => ['at' => $c['at'] ?? null, 'by' => $c['by'] ?? null, 'name' => $c['name'] ?? 'Curse'])
            ->filter(fn ($c) => $c['at'] !== null)
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
        $zones = ActionLog::query()
            ->where('session_id', $session->id)
            ->where('type', 'choose_station')
            ->orderBy('created_at')
            ->get(['payload', 'created_at'])
            ->map(fn (ActionLog $log) => isset($log->payload['lat'], $log->payload['lng'])
                ? ['at' => $log->created_at?->timestamp, 'lat' => (float) $log->payload['lat'], 'lng' => (float) $log->payload['lng'], 'radius_m' => $zoneRadius]
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

        $skip = ['ask_question', 'answer_question', 'amend_answer', 'play_curse'];
        foreach (ActionLog::query()->where('session_id', $session->id)->orderBy('created_at')->get(['type', 'player_id', 'created_at']) as $log) {
            if (in_array($log->type, $skip, true)) {
                continue;
            }
            $events[] = [
                'at' => $log->created_at?->timestamp,
                'kind' => 'action',
                'label' => ucfirst(str_replace('_', ' ', $log->type)),
                'by' => $names[$log->player_id] ?? null,
            ];
        }

        foreach ($questions as $q) {
            $events[] = [
                'at' => $q['at'],
                'kind' => 'ask',
                'label' => ucfirst((string) $q['category']).' question'.($q['answer'] ? ' → '.str_replace('_', ' ', (string) $q['answer']) : ''),
                'by' => $names[$q['by']] ?? null,
            ];
        }

        foreach ($curses as $c) {
            $events[] = ['at' => $c['at'], 'kind' => 'curse', 'label' => 'Curse: '.$c['name'], 'by' => $names[$c['by']] ?? null];
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
