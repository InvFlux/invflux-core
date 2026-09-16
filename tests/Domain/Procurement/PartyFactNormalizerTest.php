<?php

declare(strict_types=1);

namespace Tests\Domain\Procurement;

use Nandan108\InvFlux\Domain\Procurement\PartyFactNormalizer;
use PHPUnit\Framework\TestCase;

/**
 * The per-field rules the party digest compares through.
 *
 * {@see DocumentPartyDigestTest} covers what these mean for a party's *key*; this covers the rules
 * themselves, including the cases where the right answer is to leave a value alone. Those matter
 * most: a rule that fires when it should not is the expensive kind of wrong, since two parties made
 * to share a row lose the fact that they were ever stated differently.
 */
final class PartyFactNormalizerTest extends TestCase
{
    /**
     * @param array<string, ?string> $facts
     *
     * @return array<string, string>
     */
    private static function project(array $facts): array
    {
        return PartyFactNormalizer::project($facts);
    }

    /**
     * The three shapes a country arrives in — a code, WooCommerce's `CC:SUBDIVISION`, and a display
     * name in whatever language the site runs in — are one country.
     *
     * The `CC:SUBDIVISION` case is not hypothetical: splitting it already existed in three separate
     * copies across core and the adapter before this class did.
     *
     * @dataProvider countriesThatMeanSwitzerland
     */
    public function testACountryResolvesToItsAlpha2Code(string $stated): void
    {
        self::assertSame(['country' => 'CH'], self::project(['country' => $stated]));
    }

    /** @return iterable<string, array{string}> */
    public static function countriesThatMeanSwitzerland(): iterable
    {
        yield 'alpha-2' => ['CH'];
        yield 'lowercase alpha-2' => ['ch'];
        yield 'WooCommerce store-country shape' => ['CH:GE'];
        yield 'alpha-3' => ['CHE'];
        yield 'numeric' => ['756'];
        yield 'English' => ['Switzerland'];
        yield 'French' => ['Suisse'];
        yield 'German' => ['Schweiz'];
        yield 'Italian' => ['Svizzera'];
        yield 'unaccented, mixed case' => ['SUISSE'];
    }

    /**
     * A value no table recognises is left as it is rather than guessed at. Under-merging costs one
     * duplicate row; a wrong match files a party under another country entirely.
     */
    public function testAnUnrecognisedCountryIsNotGuessedAt(): void
    {
        self::assertSame(['country' => 'freedonia'], self::project(['country' => 'Freedonia']));
    }

    /**
     * **Two unknown countries must stay two countries.** The tempting implementation drops a value
     * the table cannot resolve — and then every party in an unrecognised country shares one empty
     * country fact, so parties that differ *only* by being in different places collapse into one
     * row. That is the merge-two-distinct-things failure, which is strictly worse than the dedup
     * that passing the value through gives up.
     */
    public function testTwoDifferentUnknownCountriesDoNotCollapse(): void
    {
        $facts = ['name' => 'Acme', 'city' => 'Capital'];

        $a = self::project($facts + ['country' => 'Freedonia']);
        $b = self::project($facts + ['country' => 'Sylvania']);

        self::assertNotSame($a, $b);
        self::assertArrayHasKey('country', $a, 'an unresolvable country is still a fact, not an absence');
        self::assertNotSame('', $a['country']);
    }

    /**
     * Historical names outlive their renaming on paper long after a standards body moves on, so
     * both must reach the same key — and ICU's *deprecated* region codes must not be what answers,
     * or `DD` claims Germany and `FX` claims France.
     *
     * @dataProvider renamedCountries
     */
    public function testARenamedCountryResolvesFromEitherName(string $stated, string $expected): void
    {
        self::assertSame(['country' => $expected], self::project(['country' => $stated]));
    }

    /** @return iterable<string, array{string, string}> */
    public static function renamedCountries(): iterable
    {
        yield 'Burma' => ['Burma', 'MM'];
        yield 'Myanmar' => ['Myanmar', 'MM'];
        yield 'Czech Republic' => ['Czech Republic', 'CZ'];
        yield 'Czechia' => ['Czechia', 'CZ'];
        yield 'Swaziland' => ['Swaziland', 'SZ'];
        yield 'Germany, not East Germany' => ['Germany', 'DE'];
        yield 'France, not Metropolitan France' => ['France', 'FR'];
        yield 'Russia, not the Soviet Union' => ['Russia', 'RU'];
    }

    /**
     * The case the fixture data actually produced: one person's number written internationally on
     * one address and nationally on the other. Thirteen of sixty-five such pairs in the source were
     * the same number in two hands.
     *
     * @dataProvider oneSwissNumber
     */
    public function testOneNumberInTwoHandsReducesAlike(string $stated): void
    {
        self::assertSame(
            ['country' => 'CH', 'contact_phone' => '791234567'],
            self::project(['country' => 'CH', 'contact_phone' => $stated]),
        );
    }

    /** @return iterable<string, array{string}> */
    public static function oneSwissNumber(): iterable
    {
        yield 'national' => ['079 123 45 67'];
        yield 'national, unspaced' => ['0791234567'];
        yield 'international' => ['+41 79 123 45 67'];
        yield 'international, unspaced' => ['+41791234567'];
        yield 'international with 00' => ['0041 79 123 45 67'];
        yield 'punctuated' => ['+41 (0)79 123.45.67'];
    }

    /**
     * Italy keeps its trunk zero in international form, where Switzerland drops it. Stripping the
     * country code *and then* a leading zero handles both without a per-country rule — which is the
     * reason this reduces to national digits rather than attempting true E.164.
     */
    public function testACountryThatKeepsItsTrunkZeroReducesAlike(): void
    {
        $national = self::project(['country' => 'IT', 'contact_phone' => '06 1234 5678']);
        $international = self::project(['country' => 'IT', 'contact_phone' => '+39 06 1234 5678']);

        self::assertSame($national, $international);
        self::assertSame('612345678', $national['contact_phone']);
    }

    /**
     * A number written for a different country than the party's is somebody else's local number.
     * Reducing it against this party's dialling code would be a guess, so it keeps its `+`.
     */
    public function testAForeignNumberIsNotReducedAgainstThisPartysCountry(): void
    {
        self::assertSame(
            '+33612345678',
            self::project(['country' => 'CH', 'contact_phone' => '+33 6 12 34 56 78'])['contact_phone'],
        );
    }

    /** A country with no dialling code on file leaves the number whole rather than mangling it. */
    public function testANumberForAnUnlistedCountryKeepsItsDigits(): void
    {
        self::assertSame(
            '+2611234567',
            self::project(['country' => 'MG', 'contact_phone' => '+261 12 34 567'])['contact_phone'],
        );
    }

    /**
     * **A phone's country is often not the address's, and that must only ever cost a duplicate.**
     * In the source data 107 of 16,834 numbers (0.64%) carry a code other than their address's —
     * mostly Swiss numbers on Liechtenstein addresses, which genuinely are foreign numbers since
     * Liechtenstein left the Swiss numbering plan in 1999.
     *
     * The reduction cannot over-merge on that mismatch, because a country code is only ever stripped
     * when it is the party's *own*. What it does instead is under-merge: the same foreign number
     * written internationally on one document and in its own national form on another stays two
     * parties, since nothing but a per-country numbering plan could tell that a bare `06…` on a Swiss
     * address is French. That is the cheap failure, and it is the only one available here.
     */
    public function testAMismatchedCountryCodeUnderMergesAndNeverOverMerges(): void
    {
        $swissLocal = self::project(['country' => 'CH', 'contact_phone' => '+41 79 123 45 67']);
        $frenchOnASwissAddress = self::project(['country' => 'CH', 'contact_phone' => '+33 6 12 34 56 78']);
        $writtenNationally = self::project(['country' => 'CH', 'contact_phone' => '06 12 34 56 78']);

        self::assertNotSame(
            $frenchOnASwissAddress['contact_phone'],
            $swissLocal['contact_phone'],
            'a foreign number must never reduce onto a local one',
        );
        self::assertNotSame(
            $frenchOnASwissAddress['contact_phone'],
            $writtenNationally['contact_phone'],
            'the same foreign number in two forms under-merges — the cheap side, and the only one reachable',
        );
    }

    /** Identifiers compare without their separators: one registration, one postcode. */
    public function testIdentifiersLoseTheirSeparators(): void
    {
        self::assertSame(
            ['tax_number' => 'che123456789', 'postcode' => 'sw1a1aa'],
            self::project(['tax_number' => 'CHE-123.456.789', 'postcode' => 'SW1A 1AA']),
        );
    }

    /**
     * Prose keeps its punctuation, unlike an identifier. `Rue Victor-Hugo` and `Rue Victor Hugo` are
     * therefore two parties.
     *
     * **A deliberate under-merge, and the one rule here most worth revisiting.** Folding the hyphen
     * to a space would join them, and in French street names that is nearly always right. It is left
     * undone because prose is where a separator can carry meaning and this projection's uncertain
     * cases take the cheap side — a duplicate row, rather than two addresses made one. If real data
     * shows the hyphen forking keys often, this is the rule to change, and changing it is a
     * {@see PartyFactNormalizer::VERSION} bump like any other.
     */
    public function testProseKeepsItsPunctuation(): void
    {
        self::assertSame(['address_1' => 'rue victor-hugo 3'], self::project(['address_1' => 'Rue Victor-Hugo 3']));
        self::assertNotSame(
            self::project(['address_1' => 'Rue Victor Hugo 3']),
            self::project(['address_1' => 'Rue Victor-Hugo 3']),
        );
    }

    /**
     * A value that is only whitespace states nothing, so it must become *absent* rather than a fact
     * whose content is a space — otherwise a non-breaking space forks a key against a party that
     * simply left the field blank.
     */
    public function testAValueThatIsOnlySeparatorsBecomesAbsent(): void
    {
        self::assertSame(
            ['name' => 'acme'],
            self::project(['name' => 'Acme', 'address_2' => "\u{00a0} \t", 'company' => '', 'state' => null]),
        );
    }

    /**
     * The fold is deliberately incomplete: `Ł` carries no combining mark and survives it. Closing
     * the gap needs a transliterator, and ICU's output moves with the host's library version — a key
     * that depends on which machine computed it breaks the cross-installation union this table
     * exists for. Under-merging is the cheap side, so this documents the gap rather than hiding it.
     */
    public function testTheAccentFoldIsIncompleteByDesign(): void
    {
        self::assertNotSame(
            self::project(['city' => 'Łódź']),
            self::project(['city' => 'Lodz']),
        );
        self::assertSame(['city' => 'łodz'], self::project(['city' => 'Łódź']));
    }

    /** `ß` and `SS` are one word to Unicode's caseless matching, which is why it is the fold used. */
    public function testCaselessMatchingHandlesTheSharpS(): void
    {
        self::assertSame(self::project(['address_1' => 'Straße 1']), self::project(['address_1' => 'STRASSE 1']));
    }
}
