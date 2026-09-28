<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Domain\Label;

/**
 * The one place two locales are related to each other.
 *
 * A merchant translates into the languages their staff read, not into every region of them: a
 * shop that filled in `fr_FR` should still serve a reader whose profile says `fr_BE`, because a
 * Belgian reading French prefers French to the untranslated default. So a lookup falls back
 * along the language, and stops there — `fr_FR` never answers for `de_DE`.
 *
 * Kept host-free and side-effect-free so the rule is the same wherever it runs: resolving a
 * viewer's label, rendering a supplier document in the document's own language, or a test.
 *
 * @api
 */
final class LocaleFallback
{
    /**
     * The best available text for `$locale`, or null when the language is not covered at all.
     *
     * In order: the exact locale; the bare language (`fr`), which is the closest thing to a
     * region-neutral answer; then any other region of that language, lowest code first. That
     * last step is arbitrary between equals and deliberately **stable** — a reader on `fr_BE`
     * with `fr_CA` and `fr_FR` on file gets `fr_CA` every time rather than whichever row the
     * database happened to return first, because a label that changes between two page loads
     * reads as corruption.
     *
     * @param array<string, string> $byLocale text keyed by locale, as the repository returns it
     */
    public static function pick(array $byLocale, string $locale): ?string
    {
        if (isset($byLocale[$locale])) {
            return $byLocale[$locale];
        }

        $language = self::language($locale);
        if (isset($byLocale[$language])) {
            return $byLocale[$language];
        }

        $sameLanguage = [];
        foreach ($byLocale as $candidate => $text) {
            if (self::language($candidate) === $language) {
                $sameLanguage[$candidate] = $text;
            }
        }
        if ([] === $sameLanguage) {
            return null;
        }
        ksort($sameLanguage);

        return reset($sameLanguage);
    }

    /**
     * The language part of a locale — `fr_BE` → `fr`, `ca` → `ca`.
     *
     * WordPress writes a locale as `language_REGION` with an underscore; a BCP-47 hyphen is
     * accepted too, so a locale that arrived from a browser or an import still matches one that
     * arrived from `determine_locale()`.
     */
    public static function language(string $locale): string
    {
        $separator = strcspn($locale, '_-');

        return strtolower(substr($locale, 0, $separator));
    }
}
