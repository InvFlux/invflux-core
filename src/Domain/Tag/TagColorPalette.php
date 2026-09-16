<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Domain\Tag;

/**
 * The fixed tag-colour palette, modeled on Gmail's label palette — 24 entries,
 * each a `(background, foreground)` pair pre-tuned for WCAG-AA contrast. Tags
 * store a {@see Tag::$color_id} index (0..23) into this palette rather than a
 * free-form hex, so visuals stay consistent and accessible (arbitrary merchant
 * hex is not allowed).
 *
 * The RGB values themselves live where they render — the `@invflux/ui` TypeScript
 * palette constant — and authoritatively in the doc table (§2.2). This class is
 * the PHP-side guard over the index range; the RGB pairs can be mirrored here if
 * a server-side render path (e.g. notification emails) ever needs them.
 */
final class TagColorPalette
{
    /** Number of palette entries (valid `color_id` is `0 .. COUNT - 1`). */
    public const COUNT = 24;

    /** Default index for tags created without an explicit colour — light grey. */
    public const DEFAULT_ID = 0;

    public static function isValidId(int $colorId): bool
    {
        return $colorId >= 0 && $colorId < self::COUNT;
    }
}
