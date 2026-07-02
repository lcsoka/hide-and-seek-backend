<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Card;
use App\Models\Question;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * User-generated content: a registered user's own custom curses + questions. Custom curses join
 * the deck of games they host; custom (photo) questions join those games' catalogue. Guests can't
 * author (their content would be pruned with them).
 */
class ContentController extends Controller
{
    /** Everything the current user has authored. */
    public function index(Request $request): JsonResponse
    {
        $userId = $request->user()->id;

        return response()->json([
            'curses' => Card::where('user_id', $userId)->orderByDesc('id')->get()->map(fn (Card $c) => $this->curse($c)),
            'questions' => Question::where('user_id', $userId)->orderByDesc('id')->get()->map(fn (Question $q) => $this->question($q)),
        ]);
    }

    public function storeCurse(Request $request): JsonResponse
    {
        $this->guardRegistered($request);
        $data = $this->validateCurse($request);

        $card = Card::create([
            'type' => 'curse',
            'user_id' => $request->user()->id,
            'is_custom' => true,
            'is_active' => true,
            'count' => 1,
            'sort' => 0,
            'key' => 'custom.'.Str::uuid(),
            'name' => $this->bilingual($data['name']),
            'cost' => $this->bilingual($data['cost'] ?? ''),
            'description' => $this->bilingual($data['description'] ?? ''),
            'effect' => $this->curseEffect($data),
        ]);

        return response()->json($this->curse($card), 201);
    }

    public function updateCurse(Request $request, Card $card): JsonResponse
    {
        $this->guardOwner($request, $card->user_id);
        $data = $this->validateCurse($request);

        $card->update([
            'name' => $this->bilingual($data['name']),
            'cost' => $this->bilingual($data['cost'] ?? ''),
            'description' => $this->bilingual($data['description'] ?? ''),
            'effect' => $this->curseEffect($data),
        ]);

        return response()->json($this->curse($card->refresh()));
    }

    public function destroyCurse(Request $request, Card $card): JsonResponse
    {
        $this->guardOwner($request, $card->user_id);
        $card->delete();

        return response()->json(['message' => 'Deleted.']);
    }

    public function storeQuestion(Request $request): JsonResponse
    {
        $this->guardRegistered($request);
        $data = $this->validateQuestion($request);

        $question = Question::create([
            'user_id' => $request->user()->id,
            'category' => 'photo', // the only user-authorable category (no geo evaluator needed)
            'is_custom' => true,
            'is_active' => true,
            'reward_draw' => 1,
            'reward_keep' => 1,
            'answer_time_s' => 600,
            'sort' => 0,
            'key' => 'custom.'.Str::uuid(),
            'title' => $this->bilingual($data['title']),
            'prompt' => $this->bilingual($data['prompt']),
        ]);

        return response()->json($this->question($question), 201);
    }

    public function updateQuestion(Request $request, Question $question): JsonResponse
    {
        $this->guardOwner($request, $question->user_id);
        $data = $this->validateQuestion($request);

        $question->update([
            'title' => $this->bilingual($data['title']),
            'prompt' => $this->bilingual($data['prompt']),
        ]);

        return response()->json($this->question($question->refresh()));
    }

    public function destroyQuestion(Request $request, Question $question): JsonResponse
    {
        $this->guardOwner($request, $question->user_id);
        $question->delete();

        return response()->json(['message' => 'Deleted.']);
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function validateCurse(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'cost' => ['nullable', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:400'],
            'requires_proof' => ['boolean'],
            'blocks_asking' => ['boolean'],
            'duration_minutes' => ['nullable', 'integer', 'min:1', 'max:180'],
        ]);
    }

    /** @return array<string, mixed> */
    private function validateQuestion(Request $request): array
    {
        return $request->validate([
            'title' => ['required', 'string', 'max:80'],
            'prompt' => ['required', 'string', 'max:400'],
        ]);
    }

    /** @param  array<string, mixed>  $data */
    private function curseEffect(array $data): ?array
    {
        return Card::cleanEffect([
            'requires_proof' => ! empty($data['requires_proof']),
            'blocks_asking' => ! empty($data['blocks_asking']),
            'duration_s' => ! empty($data['duration_minutes']) ? (int) $data['duration_minutes'] * 60 : null,
        ]);
    }

    /** Custom content is single-language input, stored for both locales so it always renders. */
    private function bilingual(string $text): array
    {
        return ['en' => $text, 'hu' => $text];
    }

    private function guardRegistered(Request $request): void
    {
        abort_if($request->user()->isGuest(), 403, 'Create an account to save custom content.');
    }

    private function guardOwner(Request $request, ?int $ownerId): void
    {
        abort_if($ownerId === null || $ownerId !== $request->user()->id, 403, 'Not your content.');
    }

    /** @return array<string, mixed> */
    private function curse(Card $card): array
    {
        return [
            'id' => $card->id,
            'name' => $card->getTranslation('name', 'en'),
            'cost' => $card->getTranslation('cost', 'en'),
            'description' => $card->getTranslation('description', 'en'),
            'requires_proof' => (bool) ($card->effect['requires_proof'] ?? false),
            'blocks_asking' => (bool) ($card->effect['blocks_asking'] ?? false),
            'duration_minutes' => isset($card->effect['duration_s']) ? (int) $card->effect['duration_s'] / 60 : null,
        ];
    }

    /** @return array<string, mixed> */
    private function question(Question $question): array
    {
        return [
            'id' => $question->id,
            'title' => $question->getTranslation('title', 'en'),
            'prompt' => $question->getTranslation('prompt', 'en'),
        ];
    }
}
