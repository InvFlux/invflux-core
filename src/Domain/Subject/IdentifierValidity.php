<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Domain\Subject;

/**
 * Shared sentinel for open-ended identifier validity windows.
 *
 * Storing a non-null sentinel avoids MySQL NULL uniqueness quirks that would
 * permit multiple "active" assignments for the same (system, type, value) tuple.
 *
 * @api
 */
final class IdentifierValidity
{
    public const OPEN_ENDED = '9999-12-31 23:59:59.999999';

    /**
     * The test for "open-ended": a row is open-ended when `valid_to >= OPEN_ENDED_FLOOR`.
     *
     * Match open-ended rows with this, never by equality with {@see self::OPEN_ENDED}. The value
     * written is exact, but the value read back depends on the store's fractional-second precision:
     * a store that keeps none holds `9999-12-31 23:59:59`, which compares as text against the
     * sentinel and is never equal to it. Every open-ended value, at any precision, sorts at or above
     * this floor, and no real expiry date does.
     */
    public const OPEN_ENDED_FLOOR = '9999-12-31 00:00:00';

    public static function openEnded(): \DateTimeImmutable
    {
        return new \DateTimeImmutable(self::OPEN_ENDED);
    }

    /**
     * Whether a `valid_to` read back from storage marks an open-ended row, at any precision.
     *
     * A string is compared as the store returned it (`Y-m-d H:i:s` with or without a fraction),
     * which orders correctly against the floor because the format is fixed-width up to the seconds.
     */
    public static function isOpenEnded(string | \DateTimeInterface $validTo): bool
    {
        $value = $validTo instanceof \DateTimeInterface ? $validTo->format('Y-m-d H:i:s') : $validTo;

        return $value >= self::OPEN_ENDED_FLOOR;
    }
}
