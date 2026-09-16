<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Util;

/**
 * Wire-format helpers for the read surfaces that feed the admin SPAs.
 *
 * Distinct from {@see Row}, which is a generic coercion utility: what lives here is a
 * *contract* about how InvFlux presents values to its clients. Anything added here should
 * be a decision a consumer depends on, not a convenience.
 *
 * @api
 */
final class Wire
{
    /**
     * Canonical wire format for every timestamp a read surface sends to a client:
     * ISO 8601, millisecond precision, explicit `Z` (UTC).
     *
     * The `Z` is load-bearing — without it the browser's `Date.parse` reads the string as
     * *local* time, skewing every "N ago" and wall-clock render by the client's UTC offset.
     * Stored timestamps are UTC wall-clock (`DATETIME(6)`); this only makes that explicit
     * on the wire. Single source of truth: controllers and timeline builders alike format
     * through {@see utcIso()} rather than restating the format.
     */
    public const DATETIME_FORMAT = 'Y-m-d\TH:i:s.v\Z';

    /** Format a datetime for the wire (ISO 8601 + `Z`), or pass `null` through. */
    public static function utcIso(?\DateTimeImmutable $dt): ?string
    {
        return $dt?->setTimezone(new \DateTimeZone('UTC'))->format(self::DATETIME_FORMAT);
    }

    /**
     * Read a naive-UTC `DATETIME` column off a raw-SQL row and emit it in wire format.
     *
     * The shorthand for read services that assemble their wire array straight from rows
     * (the OrderEvent timeline, say) rather than routing a `\DateTimeImmutable` through a
     * DTO. Absent or unparseable columns yield `null` — a timestamp is presentational
     * here, so a strict read would fail a whole response over one cosmetic field.
     *
     * @param array<string, mixed> $row
     */
    public static function rowUtcIso(array $row, string $key): ?string
    {
        return self::utcIso(Row::datetime($row, $key, null));
    }
}
