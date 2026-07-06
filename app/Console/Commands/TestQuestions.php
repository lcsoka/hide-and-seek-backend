<?php

namespace App\Console\Commands;

use App\Enums\QuestionCategory;
use App\Game\Questions\QuestionEvaluatorRegistry;
use App\Game\Support\Geo;
use App\Models\Player;
use App\Models\Question;
use App\Models\Session;
use Illuminate\Console\Command;
use Throwable;

/**
 * Dev harness: run every geo question (Matching / Measuring / Tentacles) through the REAL evaluator
 * chain against the configured Overpass (point OVERPASS_ENDPOINT at a local instance), at a fixed
 * hider + seeker coordinate. Prints the answer and the matched OSM entity per question, so you can
 * eyeball the whole catalog against live data without running a multiplayer session.
 *
 *   php artisan questions:test
 *   php artisan questions:test --city=debrecen
 *   php artisan questions:test --seeker=47.4979,19.0402 --hider=47.5065,19.0490 --category=tentacles
 */
class TestQuestions extends Command
{
    protected $signature = 'questions:test
        {--city= : A play city from config game.cities — sets seeker=center, hider ~1.2km away}
        {--seeker= : Seeker/asker coordinate as "lat,lng"}
        {--hider= : Hider coordinate as "lat,lng"}
        {--category=* : Limit to categories (matching, measuring, tentacles, radar); default: the three geo ones}';

    protected $description = 'Evaluate every Matching/Measuring/Tentacles question against Overpass at fixed coordinates (dev harness).';

    public function handle(QuestionEvaluatorRegistry $registry): int
    {
        [$seeker, $hider] = $this->resolveCoords();
        $categories = $this->resolveCategories();
        if ($categories === []) {
            $this->error('No valid categories selected.');

            return self::FAILURE;
        }

        $questions = Question::query()
            ->where('is_active', true)
            ->whereIn('category', $categories)
            ->orderBy('category')->orderBy('sort')
            ->get();

        if ($questions->isEmpty()) {
            $this->warn('No active questions found — seed them first: php artisan db:seed --class=QuestionSeeder');

            return self::FAILURE;
        }

        // Unsaved models are enough: the evaluators only read the hider point from state_data and the
        // seeker's last_lat/lng — no persistence, no session/players rows, no side effects.
        $session = new Session(['state_data' => ['hider_position' => ['lat' => $hider[0], 'lng' => $hider[1]]]]);
        $asker = new Player(['last_lat' => $seeker[0], 'last_lng' => $seeker[1]]);

        $gap = Geo::distanceMeters($seeker[0], $seeker[1], $hider[0], $hider[1]);
        $this->newLine();
        $this->line("  <options=bold>Overpass:</> ".config('game.overpass.endpoint'));
        $this->line(sprintf('  <options=bold>Seeker:</>  %.5f, %.5f', $seeker[0], $seeker[1]));
        $this->line(sprintf('  <options=bold>Hider:</>   %.5f, %.5f   (%.0f m apart)', $hider[0], $hider[1], $gap));
        $this->newLine();

        $rows = [];
        $stats = ['ok' => 0, 'manual' => 0, 'error' => 0, 'ms' => 0.0];

        foreach ($questions as $q) {
            $category = $q->category instanceof QuestionCategory ? $q->category : QuestionCategory::from($q->category);
            $evaluator = $registry->for($category);
            $params = $q->parameters ?? [];
            $feature = $params['feature'] ?? (isset($params['admin_level']) ? "admin_level={$params['admin_level']}" : '—');

            $result = null;
            $error = null;
            $t0 = microtime(true);
            try {
                $result = $evaluator?->evaluate($session, $asker, $q, []);
            } catch (Throwable $e) {
                $error = $e->getMessage();
            }
            $ms = (microtime(true) - $t0) * 1000;
            $stats['ms'] += $ms;

            [$status, $matched] = $this->classify($result, $error, isset($params['feature']), $stats);

            $rows[] = [
                $category->value,
                $q->key,
                $feature,
                $status,
                $matched,
                $error ? '—' : sprintf('%.0f', $ms),
            ];
        }

        $this->table(['Category', 'Key', 'Feature', 'Result', 'Matched entity', 'ms'], $rows);

        $this->newLine();
        $this->line(sprintf(
            '  <fg=green>✅ %d evaluated</>   <fg=yellow>⊘ %d manual/not-OSM</>   <fg=red>❌ %d error</>   ·   %d Overpass calls in %.0f ms',
            $stats['ok'], $stats['manual'], $stats['error'], $stats['ok'], $stats['ms']
        ));
        $this->newLine();

        return $stats['error'] > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Turn a raw evaluator result into a coloured status + a matched-entity string, and tally stats.
     *
     * @return array{0: string, 1: string}
     */
    private function classify(?array $result, ?string $error, bool $hasFeature, array &$stats): array
    {
        if ($error !== null) {
            $stats['error']++;

            return ['<fg=red>❌ error</>', '<fg=red>'.$this->truncate($error, 60).'</>'];
        }

        if ($result === null) {
            // No feature key (admin division / border / sea level …) → the evaluator can't auto-answer;
            // that's a manual question, NOT a failure. A null WITH a feature is a real problem worth a look.
            if ($hasFeature) {
                $stats['error']++;

                return ['<fg=red>❌ null</>', '<fg=red>feature set but no map result — Overpass empty?</>'];
            }
            $stats['manual']++;

            return ['<fg=yellow>⊘ manual</>', 'not OSM-backed (admin/border/etc.)'];
        }

        $stats['ok']++;
        $answer = $result['answer'] ?? '?';

        if ($answer === 'out_of_range') {
            return ['<fg=cyan>✅ out_of_range</>', 'hider outside the seeker radius'];
        }

        $name = $result['feature_name'] ?? null;
        $lat = $result['feature_lat'] ?? null;
        $lng = $result['feature_lng'] ?? null;
        // Many natural features (peaks, parks, water) are unnamed in OSM — still show their coords.
        $matched = $lat !== null && $lng !== null
            ? sprintf('%s (%.4f, %.4f)', $name ?: '(unnamed)', $lat, $lng)
            : '—';

        return ["<fg=green>✅ {$answer}</>", $matched];
    }

    /** @return array{0: array{0: float, 1: float}, 1: array{0: float, 1: float}} [seeker, hider] */
    private function resolveCoords(): array
    {
        // Defaults: seeker at Budapest centre, hider ~1.2km NE (close enough that the 2km tentacles are
        // in-range, so the within() path actually hits Overpass — not just an out-of-range short-circuit).
        $seeker = [47.4979, 19.0402];
        $hider = [47.5065, 19.0490];

        if ($city = $this->option('city')) {
            $c = config("game.cities.$city");
            if ($c === null) {
                $this->warn("Unknown city '$city' — using default Budapest. Known: ".implode(', ', array_keys(config('game.cities'))));
            } else {
                $seeker = [$c['lat'], $c['lng']];
                $hider = [$c['lat'] + 0.0086, $c['lng'] + 0.0088];
            }
        }

        return [
            $this->parseLatLng($this->option('seeker')) ?? $seeker,
            $this->parseLatLng($this->option('hider')) ?? $hider,
        ];
    }

    /** @return array{0: float, 1: float}|null */
    private function parseLatLng(?string $value): ?array
    {
        if ($value === null || $value === '') {
            return null;
        }
        $parts = array_map('trim', explode(',', $value));
        if (count($parts) !== 2 || ! is_numeric($parts[0]) || ! is_numeric($parts[1])) {
            $this->warn("Ignoring malformed coordinate '$value' (expected \"lat,lng\").");

            return null;
        }

        return [(float) $parts[0], (float) $parts[1]];
    }

    /** @return list<string> category enum values */
    private function resolveCategories(): array
    {
        $map = [
            'matching' => QuestionCategory::Matching,
            'measuring' => QuestionCategory::Measuring,
            'tentacles' => QuestionCategory::Tentacles,
            'radar' => QuestionCategory::Radar,
        ];
        $chosen = $this->option('category') ?: ['matching', 'measuring', 'tentacles'];

        $out = [];
        foreach ($chosen as $c) {
            if (isset($map[$c])) {
                $out[] = $map[$c]->value;
            } else {
                $this->warn("Unknown category '$c' — valid: ".implode(', ', array_keys($map)));
            }
        }

        return $out;
    }

    private function truncate(string $s, int $len): string
    {
        return mb_strlen($s) > $len ? mb_substr($s, 0, $len - 1).'…' : $s;
    }
}
