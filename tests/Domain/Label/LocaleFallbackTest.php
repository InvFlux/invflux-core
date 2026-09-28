<?php

declare(strict_types=1);

namespace Tests\Domain\Label;

use Nandan108\InvFlux\Domain\Label\LocaleFallback;
use PHPUnit\Framework\TestCase;

/**
 * How far a merchant's translation reaches.
 *
 * A merchant translates into the languages their staff read, not into every region of them, so the
 * question this answers is the difference between a Belgian reading French and a Belgian reading
 * the untranslated default. The other half matters just as much: the reach stops at the language,
 * so a French translation never answers for a German reader.
 */
final class LocaleFallbackTest extends TestCase
{
    public function testTheExactLocaleWinsOverEveryOtherRegion(): void
    {
        $text = LocaleFallback::pick(['fr_CA' => 'Chandail', 'fr_FR' => 'Pull', 'fr' => 'Pull-over'], 'fr_FR');

        self::assertSame('Pull', $text);
    }

    public function testAnotherRegionOfTheSameLanguageAnswers(): void
    {
        // The point of the whole rule: a store that filled in French serves the colleague whose
        // profile happens to say Belgium.
        self::assertSame('Pull', LocaleFallback::pick(['fr_FR' => 'Pull'], 'fr_BE'));
    }

    public function testTheBareLanguageIsPreferredOverARegion(): void
    {
        // `fr` is the closest thing to a region-neutral answer, so it beats picking a country's.
        self::assertSame('Pull-over', LocaleFallback::pick(['fr_CA' => 'Chandail', 'fr' => 'Pull-over'], 'fr_BE'));
    }

    public function testAChoiceBetweenRegionsIsStableRatherThanWhicheverCameFirst(): void
    {
        // Arbitrary between equals, but never different between two page loads: a label that
        // changes on refresh reads as corruption, so the lowest code wins every time and the
        // insertion order of the map is irrelevant.
        $forwards = LocaleFallback::pick(['fr_CA' => 'Chandail', 'fr_FR' => 'Pull'], 'fr_BE');
        $backwards = LocaleFallback::pick(['fr_FR' => 'Pull', 'fr_CA' => 'Chandail'], 'fr_BE');

        self::assertSame('Chandail', $forwards);
        self::assertSame($forwards, $backwards);
    }

    public function testAnotherLanguageNeverAnswers(): void
    {
        self::assertNull(LocaleFallback::pick(['fr_FR' => 'Pull', 'es_ES' => 'Jersey'], 'de_DE'));
    }

    public function testNothingOnFileIsNull(): void
    {
        self::assertNull(LocaleFallback::pick([], 'fr_FR'));
    }

    public function testALocaleWithNoRegionMatchesItsOwnLanguage(): void
    {
        // Several WordPress locales are bare (`ca`, `eu`), so the language of `ca` is `ca`.
        self::assertSame('Jersei', LocaleFallback::pick(['ca' => 'Jersei'], 'ca'));
    }

    public function testAHyphenatedLocaleMatchesTheUnderscoredOne(): void
    {
        // A locale that arrived from a browser or an import is BCP-47 hyphenated; one from
        // `determine_locale()` is underscored. They name the same language either way.
        self::assertSame('Pull', LocaleFallback::pick(['fr_FR' => 'Pull'], 'fr-BE'));
        self::assertSame('fr', LocaleFallback::language('fr-CA'));
    }

    public function testTheLanguageIsLowercasedSoCasingCannotSplitIt(): void
    {
        self::assertSame('fr', LocaleFallback::language('FR_be'));
    }
}
