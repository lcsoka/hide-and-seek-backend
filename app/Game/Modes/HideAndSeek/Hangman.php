<?php

namespace App\Game\Modes\HideAndSeek;

/**
 * The Hidden Gallows curse's word puzzle. Server-authoritative: the chosen word lives only in
 * server state; seekers get a masked view (revealed letters + wrong guesses) via the presenter
 * and reveal letters through the `hangman_guess` action until the word is solved, which clears
 * the curse's asking block. Matching is accent-folded (guessing "A" reveals "Á"), so the base
 * Latin letter buttons cover every Hungarian word in the pool.
 */
final class Hangman
{
    /** Wrong guesses allowed before the puzzle resets with a fresh word (never a dead end). */
    public const MAX_WRONG = 6;

    /** Hide-and-seek / geography / transit themed words (kept recognisable, 5–10 letters). */
    private const WORDS = [
        'VILLAMOS', 'AUTÓBUSZ', 'ÁLLOMÁS', 'TÉRKÉP', 'BÚJÓCSKA', 'REJTEKHELY',
        'NYOMOZÓ', 'IRÁNYTŰ', 'VONAT', 'METRÓ', 'KERÜLET', 'FOLYÓ', 'SZIGET',
        'TEMPLOM', 'MÚZEUM', 'KÖNYVTÁR', 'REPÜLŐ', 'VÁROSHÁZA', 'BUDAPEST',
        'KASTÉLY', 'TITOK', 'MENETREND', 'PERON', 'ÁTSZÁLLÁS', 'VÉGÁLLOMÁS',
        'ÚTVONAL', 'TORONY', 'KIKÖTŐ', 'ORSZÁG', 'HATÁR', 'TÉRSÉG', 'MEGÁLLÓ',
    ];

    /** Hungarian accents folded to their base letter, so "A" matches "Á". */
    private const FOLD = [
        'Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ö' => 'O',
        'Ő' => 'O', 'Ú' => 'U', 'Ü' => 'U', 'Ű' => 'U',
    ];

    /** The guessable buttons: base Latin letters that appear in the word pool (no Q/W/X). */
    public const ALPHABET = [
        'A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'I', 'J', 'K', 'L',
        'M', 'N', 'O', 'P', 'R', 'S', 'T', 'U', 'V', 'Y', 'Z',
    ];

    /** A fresh puzzle instance (stored on the curse: the full word stays server-side). */
    public static function newState(): array
    {
        return [
            'word' => self::WORDS[array_rand(self::WORDS)],
            'guessed' => [],
            'wrong' => [],
            'max_wrong' => self::MAX_WRONG,
        ];
    }

    /** Uppercase + strip the Hungarian accent from a single guessed letter. */
    public static function fold(string $char): string
    {
        return strtr(mb_strtoupper(trim($char)), self::FOLD);
    }

    /** @return list<string> the word's letters, accent-folded */
    private static function letters(string $word): array
    {
        return array_map(self::fold(...), mb_str_split($word));
    }

    public static function wordContains(string $word, string $folded): bool
    {
        return in_array($folded, self::letters($word), true);
    }

    /** Every distinct letter of the word has been guessed. */
    public static function isSolved(string $word, array $guessed): bool
    {
        foreach (self::letters($word) as $letter) {
            if (! in_array($letter, $guessed, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * The masked word for the client: each position is its real (accented) character once its
     * folded base has been guessed, else null (a blank). The raw word is never sent.
     *
     * @return list<string|null>
     */
    public static function mask(string $word, array $guessed): array
    {
        return array_map(
            fn (string $char) => in_array(self::fold($char), $guessed, true) ? $char : null,
            mb_str_split($word),
        );
    }
}
