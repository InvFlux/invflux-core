<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Domain\Procurement;

/**
 * Terms as Markdown source, put into the one canonical form that is stored and hashed.
 *
 * **Identity depends on this, so it only removes differences nobody can see.** Two sources that
 * render identically but differ in line endings or stray trailing spaces would otherwise intern to
 * two rows, and a merchant saving terms they did not change would mint a new version of them. It
 * runs before a text is written, never inside {@see Terms::$content_hash}'s expression: a
 * normaliser inside the digest would become part of the identity function, so improving it later
 * would shift every stored hash at once.
 *
 * What it does, all of it invisible once rendered:
 *
 *  - line endings become `\n`, and a leading byte-order mark is dropped;
 *  - trailing spaces and tabs are removed from every line;
 *  - runs of blank lines collapse to one, since Markdown reads one blank line and ten alike;
 *  - blank lines at either end are removed.
 *
 * **Trailing spaces are content in one place in Markdown**: two of them end a line with a hard
 * break. They are removed anyway, because they are invisible in the editor and so cannot be told
 * from accidental ones. A hard break is written with a trailing backslash instead, which Markdown
 * treats the same way and which can be seen.
 *
 * Leading indentation is left alone: in Markdown it decides what a line *is* — a nested list item,
 * or four spaces making code of it.
 *
 * @api
 */
final class TermsSource
{
    public static function normalise(string $source): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $source);
        if (str_starts_with($text, "\u{FEFF}")) {
            $text = substr($text, 3);
        }
        $text = (string) preg_replace('/[ \t]+$/m', '', $text);
        $text = (string) preg_replace("/\n{3,}/", "\n\n", $text);

        return trim($text, "\n");
    }
}
