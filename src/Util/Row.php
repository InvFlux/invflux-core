<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Util;

/**
 * Field readers for raw-SQL result rows — the `array<string, mixed>` shapes that come
 * back from a session's `fetchAll()` / `fetchOne()`.
 *
 * These exist for the reads that attrecord cannot express by design: multi-table JOIN
 * projections, grouped aggregates, windowed reads. A `Record` knows its own column types
 * and casts them; a hand-written JOIN projection has no such declaration, so every field
 * arrives as `mixed` and has to be coerced at the read site. Doing that inline
 * (`(int) $row['qty']`) is both repetitive and silent on the failure that actually
 * matters — a mistyped or dropped column name.
 *
 * ## The `$default` contract
 *
 * Every reader takes a `$default`, and it means exactly one thing: **the value to return
 * when the column cannot be coerced to the target type** — key absent from the row, value
 * `NULL`, or value of a shape the target type can't accept (non-numeric for {@see int()},
 * unparseable for {@see datetime()}, …).
 *
 * **Omit `$default` and the read is strict**: an uncoercible column throws, naming the
 * column and distinguishing "absent" from "present but wrong shape" — a typo and a broken
 * JOIN want different investigations. Omit it wherever the SELECT list is fixed and known;
 * supply one where it genuinely varies.
 *
 * That single rule is what lets one method per type cover what used to need a
 * `nullableX` twin plus a hand-rolled `?? 0`:
 *
 * ```php
 * Row::int($row, 'subject_id')          // required — throws if absent or non-numeric
 * Row::int($row, 'staged_by', null)     // nullable on the wire
 * Row::int($row, 'external_line_ref', 0) // absent means zero
 * ```
 *
 * ## Empty strings are data, not absence
 *
 * `''` is a legitimate value in a VARCHAR column, so {@see str()} returns it unchanged and
 * a strict `str()` does not throw on it. Callers that want the *wire-shaping* behaviour —
 * collapse `''` to `null`, because a LEFT JOIN miss and an empty column should look the
 * same on the wire — ask for it by name via {@see nonEmptyStr()}. Keeping the two apart
 * means `$default` never quietly does two jobs, and buys a real `non-empty-string` out of
 * the second one.
 *
 * For numeric and temporal readers no such distinction arises: `''` is uncoercible to an
 * int or a date, so it falls through to `$default` under the ordinary rule.
 *
 * @api
 */
final class Row
{
    /**
     * Read a string column. `''` is returned as-is — see the class docblock on why empty
     * strings are data here and {@see nonEmptyStr()} is the reader that collapses them.
     *
     * @param array<string, mixed> $row
     *
     * @psalm-return ($default is null ? string|null : string)
     */
    public static function str(array $row, string $key, string | Missing | null $default = Missing::Required): ?string
    {
        /** @psalm-var mixed $value */
        $value = $row[$key] ?? null;

        if (is_scalar($value)) {
            return (string) $value;
        }
        if ($default instanceof Missing) {
            self::fail($row, $key, 'a scalar');
        }

        /** @psalm-var string|null $fallback the conditional return type leaves $default templated */
        $fallback = $default;

        return $fallback;
    }

    /**
     * Read a string column, treating `''` as absent.
     *
     * The wire-shaping counterpart to {@see str()}: a LEFT JOIN miss and a column that
     * holds an empty string are the same fact as far as the consumer is concerned, so both
     * yield `$default`. Because the empty case is excluded, what comes back is a genuine
     * `non-empty-string`.
     *
     * @param array<string, mixed> $row
     *
     * @psalm-param non-empty-string|null|Missing $default
     *
     * @psalm-return ($default is null ? non-empty-string|null : non-empty-string)
     */
    public static function nonEmptyStr(array $row, string $key, string | Missing | null $default = Missing::Required): ?string
    {
        /** @psalm-var mixed $value */
        $value = $row[$key] ?? null;

        if (is_scalar($value)) {
            $string = (string) $value;
            if ('' !== $string) {
                return $string;
            }
        }

        if ($default instanceof Missing) {
            self::fail($row, $key, 'a non-empty scalar');
        }

        /** @psalm-var non-empty-string|null $fallback */
        $fallback = $default;

        return $fallback;
    }

    /**
     * Read an integer column.
     *
     * Coercion is gated on `is_numeric()` rather than a bare `(int)` cast, so a
     * non-numeric column takes the `$default` path instead of silently becoming `0` —
     * `0` is a perfectly plausible quantity, which is exactly what makes the silent
     * version dangerous.
     *
     * @param array<string, mixed> $row
     *
     * @psalm-return ($default is null ? int|null : int)
     */
    public static function int(array $row, string $key, int | Missing | null $default = Missing::Required): ?int
    {
        /** @psalm-var mixed $value */
        $value = $row[$key] ?? null;

        if (is_numeric($value)) {
            return (int) $value;
        }
        if ($default instanceof Missing) {
            self::fail($row, $key, 'numeric');
        }

        /** @psalm-var int|null $fallback */
        $fallback = $default;

        return $fallback;
    }

    /**
     * Read a DECIMAL column as the numeric **string** the driver returned.
     *
     * Deliberately not a float. MySQL hands DECIMAL back as a string precisely so exact
     * values survive the trip; casting to float at the read site throws that away before
     * the caller gets a say. Money columns (refund amounts, unit prices, costs) go through
     * here, and any rounding decision stays the caller's.
     *
     * @param array<string, mixed> $row
     *
     * @psalm-return ($default is null ? numeric-string|null : numeric-string)
     */
    public static function decimal(array $row, string $key, string | Missing | null $default = Missing::Required): ?string
    {
        /** @psalm-var mixed $value */
        $value = $row[$key] ?? null;

        if (is_numeric($value)) {
            /** @psalm-var numeric-string */
            return (string) $value;
        }
        if ($default instanceof Missing) {
            self::fail($row, $key, 'numeric');
        }

        /** @psalm-var numeric-string|null $fallback */
        $fallback = $default;

        return $fallback;
    }

    /**
     * Read a naive-UTC `DATETIME` / `DATETIME(6)` column.
     *
     * Stored timestamps carry no offset — they are UTC wall-clock by convention — so the
     * zone is applied here rather than inferred. Both the microsecond and whole-second
     * forms are accepted, since which one a column yields depends on its declared
     * precision and on whether the value came back through an aggregate.
     *
     * To put one of these on the wire, hand the result to {@see Wire::utcIso()}.
     *
     * @param array<string, mixed> $row
     *
     * @psalm-return ($default is null ? \DateTimeImmutable|null : \DateTimeImmutable)
     */
    public static function datetime(array $row, string $key, \DateTimeImmutable | Missing | null $default = Missing::Required): ?\DateTimeImmutable
    {
        /** @psalm-var mixed $value */
        $value = $row[$key] ?? null;

        if (is_string($value) && '' !== $value) {
            $utc = new \DateTimeZone('UTC');
            foreach (['Y-m-d H:i:s.u', 'Y-m-d H:i:s'] as $format) {
                $parsed = \DateTimeImmutable::createFromFormat($format, $value, $utc);
                if (false !== $parsed) {
                    return $parsed;
                }
            }
        }

        if ($default instanceof Missing) {
            self::fail($row, $key, 'a UTC datetime string');
        }

        /** @psalm-var \DateTimeImmutable|null $fallback */
        $fallback = $default;

        return $fallback;
    }

    /**
     * Read a `BINARY` id column and hex-encode it for the wire.
     *
     * Strict by default, and pointedly so: `bin2hex()` over a silently-defaulted `''`
     * yields `''`, which travels on as an empty-but-well-formed id and fails somewhere
     * far from the mistyped column that produced it.
     *
     * @param array<string, mixed> $row
     *
     * @psalm-return ($default is null ? non-empty-string|null : non-empty-string)
     */
    public static function hexId(array $row, string $key, string | Missing | null $default = Missing::Required): ?string
    {
        /** @psalm-var mixed $value */
        $value = $row[$key] ?? null;

        if (is_string($value) && '' !== $value) {
            return bin2hex($value);
        }
        if ($default instanceof Missing) {
            self::fail($row, $key, 'a non-empty binary string');
        }

        /** @psalm-var non-empty-string|null $fallback */
        $fallback = $default;

        return $fallback;
    }

    /**
     * Build the failure for a strict read, separating the two causes: a column that isn't
     * in the row at all is almost always a typo or a stale SELECT list, while one that is
     * present but of the wrong shape points at the JOIN or the schema.
     *
     * @param array<string, mixed> $row
     *
     * @psalm-return never
     */
    private static function fail(array $row, string $key, string $expected): never
    {
        throw new \InvalidArgumentException(\array_key_exists($key, $row)
            ? \sprintf('Row column "%s" is not %s.', $key, $expected)
            : \sprintf('Row column "%s" is absent (row has: %s).', $key, implode(', ', array_keys($row))));
    }
}
