<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Domain\Procurement;

/**
 * Prose made comparable: single-spaced, caseless and unaccented — the projection a party's facts
 * are keyed on ({@see PartyFactNormalizer}).
 *
 * **No runtime dependency on the intl extension or on ICU.** Removing accents means decomposing
 * each character and dropping the combining marks that exposes; the decompositions come from a
 * table generated from ICU at build time ({@see UnicodeFoldTable}, `bin/gen-unicode-fold.php`), the
 * same rule {@see CountryCodes} follows. Two reasons. A host without intl — WordPress Playground,
 * some shared hosting — must still fold, rather than fail. And a party's key must not depend on
 * which machine computed it: ICU's data moves with the host's library version, while Unicode's
 * stability policy fixes the canonical decomposition of every assigned character for good, so a
 * pinned table stays right. Hangul syllables decompose by arithmetic and are computed here.
 *
 * The result equals what `Normalizer` (NFC, case fold, NFD, marks removed) gives, character for
 * character; a test holds the two equal over every code point the running ICU knows.
 */
final class UnicodeFold
{
    private const HANGUL_FIRST = 0xAC00;
    private const LEAD_BASE = 0x1100;
    private const VOWEL_BASE = 0x1161;
    private const TRAIL_BASE = 0x11A7;
    private const VOWELS_TIMES_TRAILS = 588;
    private const TRAILS = 28;

    public static function fold(string $value): string
    {
        $value = trim((string) preg_replace('/\s+/u', ' ', $value));
        $value = mb_convert_case($value, \MB_CASE_FOLD, 'UTF-8');
        $value = self::decomposeHangul(strtr($value, UnicodeFoldTable::MAP));

        // Composed and decomposed spellings look identical on screen and differ byte for byte;
        // with the marks gone, both reach the same string.
        return (string) preg_replace('/\p{Mn}+/u', '', $value);
    }

    /** A Hangul syllable into its leading consonant, vowel and optional trailing consonant. */
    private static function decomposeHangul(string $value): string
    {
        return (string) preg_replace_callback(
            '/[\x{AC00}-\x{D7A3}]/u',
            static function (array $m): string {
                // A matched syllable always converts: the casts only settle the declared types.
                $index = (int) mb_ord($m[0], 'UTF-8') - self::HANGUL_FIRST;
                $trail = $index % self::TRAILS;

                return (string) mb_chr(self::LEAD_BASE + intdiv($index, self::VOWELS_TIMES_TRAILS), 'UTF-8')
                    .(string) mb_chr(self::VOWEL_BASE + intdiv($index % self::VOWELS_TIMES_TRAILS, self::TRAILS), 'UTF-8')
                    .(0 === $trail ? '' : (string) mb_chr(self::TRAIL_BASE + $trail, 'UTF-8'));
            },
            $value,
        );
    }
}
