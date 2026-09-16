<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Util;

/**
 * Generate ULIDs (Universally Unique Lexicographically Sortable Identifiers).
 *
 * A ULID is a 26-character base32 string composed of:
 *   - 10 chars  (48 bits) Crockford-base32-encoded millisecond timestamp
 *   - 16 chars  (80 bits) Crockford-base32-encoded randomness
 *
 * Properties:
 *   - URL-safe and case-insensitive (no `0`/`O` confusion: Crockford excludes `I`,
 *     `L`, `O`, `U`)
 *   - Sorts chronologically as a string (`ORDER BY ulid` ≈ `ORDER BY time_created`)
 *   - Collision-free in practice within the same millisecond (80 random bits)
 *   - Fixed-length (26 chars) — friendlier than UUID for index locality
 *
 * Used as `correlation_id` to group related events on the dispatch order-events
 * timeline; useful as a general-purpose external identifier anywhere a
 * time-sortable opaque token is preferable to an auto-increment.
 */
final class Ulid
{
    /** Crockford base32 alphabet — excludes `I`, `L`, `O`, `U` to avoid visual confusion. */
    private const ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

    /** Generate one new ULID using current time and cryptographically-secure randomness. */
    public static function generate(): string
    {
        return self::encodeTime((int) (microtime(true) * 1000.0)).self::encodeRandom();
    }

    /**
     * Validate that a string is a syntactically well-formed ULID.
     *
     * Does not verify the embedded timestamp is plausible — only the shape.
     */
    public static function isValid(string $value): bool
    {
        if (26 !== strlen($value)) {
            return false;
        }

        return 1 === preg_match('/^[0123456789ABCDEFGHJKMNPQRSTVWXYZ]{26}$/', strtoupper($value));
    }

    /** Encode the millisecond timestamp into 10 Crockford base32 chars. */
    private static function encodeTime(int $millis): string
    {
        $encoded = '';
        for ($i = 9; $i >= 0; --$i) {
            $encoded = self::ALPHABET[$millis % 32].$encoded;
            $millis = intdiv($millis, 32);
        }

        return $encoded;
    }

    /**
     * Encode 80 random bits (10 bytes) into 16 Crockford base32 chars.
     *
     * Each output char encodes 5 bits, so 16 chars = 80 bits. We read 5-bit
     * chunks from the byte array, MSB first.
     */
    private static function encodeRandom(): string
    {
        $bytes = random_bytes(10);
        $bits = '';
        foreach (str_split($bytes) as $byte) {
            $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }

        $encoded = '';
        for ($i = 0; $i < 80; $i += 5) {
            $encoded .= self::ALPHABET[(int) bindec(substr($bits, $i, 5))];
        }

        return $encoded;
    }
}
