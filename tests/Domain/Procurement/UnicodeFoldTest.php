<?php

declare(strict_types=1);

namespace Tests\Domain\Procurement;

use Nandan108\InvFlux\Domain\Procurement\UnicodeFold;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class UnicodeFoldTest extends TestCase
{
    /**
     * Party keys are stored, so the pinned fold must give exactly what the intl path gave when the
     * keys were first computed: every code point the running ICU knows, compared one by one.
     * Unassigned code points are skipped — an older ICU knows fewer, and canonical decompositions of
     * assigned characters never change (Unicode stability policy).
     */
    public function testEqualsTheIntlFoldForEveryAssignedCodePoint(): void
    {
        if (!class_exists(\Normalizer::class) || !class_exists(\IntlChar::class)) {
            self::markTestSkipped('The comparison needs the intl extension; the fold itself does not.');
        }

        $mismatches = [];
        for ($cp = 0; $cp <= 0x10FFFF; ++$cp) {
            if ($cp >= 0xD800 && $cp <= 0xDFFF) {
                continue;
            }
            if ([0, 0, 0, 0] === \IntlChar::charAge($cp)) {
                continue;
            }
            $char = mb_chr($cp, 'UTF-8');
            if (false === $char) {
                continue;
            }
            if (self::intlFold($char) !== UnicodeFold::fold($char)) {
                $mismatches[] = sprintf('U+%04X', $cp);
            }
        }

        self::assertSame([], \array_slice($mismatches, 0, 20), \count($mismatches).' code points differ');
    }

    /** @return iterable<string, array{string, string}> */
    public static function spellings(): iterable
    {
        yield 'sharp s folds like the capitals' => ['Straße', 'strasse'];
        yield 'accents and case' => ['ÅNGSTRÖM', 'angstrom'];
        yield 'stacked Vietnamese accents' => ['Nguyễn Văn Tèo', 'nguyen van teo'];
        yield 'already decomposed' => ["E\u{0301}cole", 'ecole'];
        yield 'composed and decomposed agree' => ["\u{00E9}", 'e'];
        yield 'runs of whitespace' => ["  Zürich \t Hauptstrasse\n12 ", 'zurich hauptstrasse 12'];
        yield 'Hangul into its letters' => ['서울', "\u{1109}\u{1165}\u{110B}\u{116E}\u{11AF}"];
        yield 'a ligature folds to its letters' => ['ﬁle', 'file'];
    }

    /** Fixed expectations: these hold with or without the intl extension. */
    #[DataProvider('spellings')]
    public function testFoldsWithoutTheIntlExtension(string $value, string $folded): void
    {
        self::assertSame($folded, UnicodeFold::fold($value));
    }

    /** The fold party keys were first computed with, through intl. */
    private static function intlFold(string $value): string
    {
        $value = (string) \Normalizer::normalize($value, \Normalizer::FORM_C);
        $value = trim((string) preg_replace('/\s+/u', ' ', $value));
        $value = mb_convert_case($value, \MB_CASE_FOLD, 'UTF-8');

        return (string) preg_replace('/\p{Mn}+/u', '', (string) \Normalizer::normalize($value, \Normalizer::FORM_D));
    }
}
