<?php

declare(strict_types=1);

namespace Tests\Domain\Address;

use Nandan108\InvFlux\Domain\Address\AddressLayouts;
use Nandan108\InvFlux\Domain\Address\CountryNames;
use Nandan108\InvFlux\Domain\Address\IcuPlaceNames;
use Nandan108\InvFlux\Domain\Address\PlaceNames;
use Nandan108\InvFlux\Domain\Address\PostalAddressFormatter;
use PHPUnit\Framework\TestCase;

/**
 * Laying an address out for the country it is in.
 *
 * The assertions that matter most are the ones a storefront formatter would fail: the country line
 * is printed unconditionally, and the layout is decided by the address rather than by whoever is
 * sending the document.
 */
final class PostalAddressFormatterTest extends TestCase
{
    private const SWISS = [
        'company'         => 'Belmare Diffusion GmbH',
        'address_1'       => 'Bahnhofstrasse',
        'building_number' => '12',
        'city'            => 'Genève',
        'postcode'        => '1204',
        'country'         => 'CH',
    ];

    public function testEachCountryGetsItsOwnLayout(): void
    {
        $formatter = new PostalAddressFormatter();

        self::assertSame(
            "Belmare Diffusion GmbH\nBahnhofstrasse 12\n1204 Genève\nSwitzerland",
            $formatter->format(self::SWISS, 'en_US'),
        );

        // The same parts, declared American: the number moves to the front of the street, the
        // locality line becomes `city, ST postcode`, and the postcode moves with it.
        self::assertSame(
            "Belmare Diffusion GmbH\n12 Bahnhofstrasse\nGeneva, NY 14456\nUnited States",
            $formatter->format(
                ['company' => 'Belmare Diffusion GmbH', 'address_1' => 'Bahnhofstrasse', 'building_number' => '12',
                    'city' => 'Geneva', 'state' => 'NY', 'postcode' => '14456', 'country' => 'US'],
                'en_US',
            ),
        );
    }

    /**
     * **The finding this class exists for.** A storefront formatter drops the country when it
     * matches the store's own, because a customer knows which country they are in. A purchase order
     * is read by people the sender never meets, so the country is a property of the address and
     * nothing else — and there is no store here to compare it against.
     */
    public function testTheCountryIsPrintedWhoeverIsSendingTheDocument(): void
    {
        $formatter = new PostalAddressFormatter();
        $swiss = $formatter->format(self::SWISS, 'fr_FR');

        self::assertStringEndsWith("\nSuisse", $swiss);
        // Nothing about the sender is reachable from here, so the same address cannot render two
        // ways. Asserted rather than assumed, because "identical output" is the whole claim.
        self::assertSame($swiss, (new PostalAddressFormatter())->format(self::SWISS, 'fr_FR'));
    }

    public function testTheCountryNameIsWrittenInTheDocumentsLanguage(): void
    {
        $formatter = new PostalAddressFormatter();
        $parts = ['city' => 'Genève', 'postcode' => '1204', 'country' => 'CH'];

        self::assertStringEndsWith('Switzerland', $formatter->format($parts, 'en_US'));
        self::assertStringEndsWith('Suisse', $formatter->format($parts, 'fr_FR'));
        self::assertStringEndsWith('Schweiz', $formatter->format($parts, 'de_DE'));
    }

    /**
     * **Those names must not come from the host's ICU, and this is what says so.**.
     *
     * `extension_loaded('intl')` is true on hosts carrying English region data and nothing else —
     * this project's own wp-env cli container is one, ICU 78.1 with four locales — and there every
     * locale answers in English with no call failing. A test that only exercised the formatter
     * would pass on a full-data machine and the feature would still be broken in the container it
     * ships to. Reading the pinned table directly is what makes the assertion about the mechanism
     * rather than about this machine.
     */
    public function testTheNamesAreShippedRatherThanAskedOfTheHost(): void
    {
        self::assertSame('Suisse', CountryNames::inLocale('CH', 'fr_FR'));
        self::assertSame('Schweiz', CountryNames::inLocale('CH', 'de_DE'));
        self::assertSame('Svizzera', CountryNames::inLocale('CH', 'it_IT'));
        self::assertSame('Zwitserland', CountryNames::inLocale('CH', 'nl_NL'));
        self::assertSame('Suíça', CountryNames::inLocale('CH', 'pt_PT'));
        // The region subtag is not read: region names vary by language, not by country.
        self::assertSame('Suisse', CountryNames::inLocale('CH', 'fr-CA'));
        self::assertSame('Suisse', CountryNames::inLocale('CH', 'fr'));

        // A language we ship no wording in falls through to English rather than to a code, and a
        // region nobody names falls through to null so the caller can print the code.
        self::assertSame('Switzerland', CountryNames::inLocale('CH', 'ja_JP'));
        self::assertNull(CountryNames::inLocale('XZ', 'fr_FR'));

        // **And the resolver must actually consult it first.** Everything above is true of the
        // table whether or not anything reads it — on a full-ICU machine, deleting the lookup
        // changes no output, because the table was generated from ICU and the two agree by
        // construction. Japanese is where they cannot agree: we ship no `ja`, so the table answers
        // English while ICU answers スイス. A resolver that asked ICU first fails here and nowhere
        // else, which is the whole reason this assertion is worth its oddity.
        self::assertSame('Switzerland', (new IcuPlaceNames())->country('CH', 'ja_JP'));
    }

    /**
     * **A county is not part of a British address, so it does not reach the document.**.
     *
     * Royal Mail's standard is premises, thoroughfare, locality, post town, postcode; the county has
     * been unnecessary since postcodes, and WooCommerce ships no region list for `GB` at all — so a
     * value in that box is free text somebody typed rather than a choice the form offered.
     *
     * The county is *passed in* here and expected absent. A plain British address would pass whether
     * or not the layout named a subdivision, which is how the placeholder survived its first cut on
     * the strength of a comment: nothing exercised it.
     */
    public function testABritishCountyIsNotPartOfTheAddress(): void
    {
        self::assertSame(
            "Fen Ditton Supply Ltd\n10 Downing Street\nLONDON\nSW1A 2AA\nUnited Kingdom",
            (new PostalAddressFormatter())->format(
                ['company'            => 'Fen Ditton Supply Ltd', 'address_1' => 'Downing Street',
                    'building_number' => '10', 'city' => 'LONDON', 'state' => 'Greater London',
                    'postcode'        => 'SW1A 2AA', 'country' => 'GB'],
                'en_US',
            ),
        );
    }

    /**
     * Ireland is the opposite case, and the reason `GB` and `IE` no longer share an explanation: An
     * Post puts the county on a line of the address, above the Eircode.
     */
    public function testAnIrishCountyIsPartOfTheAddress(): void
    {
        self::assertSame(
            "Fen Ditton Supply Ltd\n10 Patrick Street\nCork\nCounty Cork\nT12 X70A\nIreland",
            (new PostalAddressFormatter())->format(
                ['company'            => 'Fen Ditton Supply Ltd', 'address_1' => 'Patrick Street',
                    'building_number' => '10', 'city' => 'Cork', 'state' => 'County Cork',
                    'postcode'        => 'T12 X70A', 'country' => 'IE'],
                'en_US',
            ),
        );
    }

    /**
     * A romanized Japanese address reads smallest-first, like a romanized Chinese or Russian one.
     *
     * Japan's own script writes the whole address inverted, postcode above everything. Which order
     * applies is decided by the script the parts are stored in — and an address box collects the
     * romanized form — so the domestic order was the wrong answer to a question the table answers
     * one way for China and Russia. This fails against that older layout, which put the postcode
     * first and ran prefecture → city → street.
     *
     * No `building_number` here on purpose: a Japanese address is block-numbered rather than
     * street-numbered, so the street/number split `StreetLine` exists for does not map onto it,
     * and a merchant pastes the whole line.
     */
    public function testARomanizedJapaneseAddressReadsSmallestFirst(): void
    {
        self::assertSame(
            "Yamato Trading K.K.\n1-1 Nihonbashi\nChuo-ku\nTokyo 103-0027\nJapan",
            (new PostalAddressFormatter())->format(
                ['company'  => 'Yamato Trading K.K.', 'address_1' => '1-1 Nihonbashi',
                    'city'  => 'Chuo-ku', 'state' => 'Tokyo', 'postcode' => '103-0027', 'country' => 'JP'],
                'en_US',
            ),
        );
    }

    /**
     * A country nobody wrote a format for still renders, and renders legibly.
     *
     * The table is partial on purpose, so this is the ordinary path for most of the world rather
     * than an error case.
     */
    public function testAnUnlistedCountryTakesThePostcodeBeforeCityFallback(): void
    {
        self::assertArrayNotHasKey('KE', AddressLayouts::LAYOUTS);

        self::assertSame(
            "Kilimani Road 8\n00100 Nairobi\nKenya",
            (new PostalAddressFormatter())->format(
                ['address_1'   => 'Kilimani Road', 'building_number' => '8', 'city' => 'Nairobi',
                    'postcode' => '00100', 'country' => 'KE'],
                'en_US',
            ),
        );
    }

    /**
     * A format naming parts the address does not carry must not print what the substitution left
     * behind — a stray comma, a run of spaces, or a blank line where a company would have gone.
     */
    public function testAbsentPartsLeaveNoResidue(): void
    {
        self::assertSame(
            "Sam de Rougemont\n742 Evergreen Terrace\nSpringfield\nUnited States",
            (new PostalAddressFormatter())->format(
                ['name'               => 'Sam de Rougemont', 'address_1' => 'Evergreen Terrace',
                    'building_number' => '742', 'city' => 'Springfield', 'country' => 'US'],
                'en_US',
            ),
        );

        // Brazil's locality line joins with a hyphen, which has to go the same way the comma does.
        self::assertSame(
            "Rua Augusta 1500\nSão Paulo\n01305-100\nBrazil",
            (new PostalAddressFormatter())->format(
                ['address_1'   => 'Rua Augusta', 'building_number' => '1500', 'city' => 'São Paulo',
                    'postcode' => '01305-100', 'country' => 'BR'],
                'en_US',
            ),
        );
    }

    /**
     * `{state}` prints a name when the host can resolve one, `{state_code}` never does.
     *
     * The split is not cosmetic: the United States, Canada and Australia print the code as the
     * postal convention, while Spain and India print the name, so one placeholder could not serve
     * both.
     */
    public function testSubdivisionNamesComeFromTheHostAndCodesNeverDo(): void
    {
        $formatter = new PostalAddressFormatter(new class implements PlaceNames {
            #[\Override]
            public function country(string $code, string $locale): string
            {
                return $code;
            }

            #[\Override]
            public function subdivision(string $countryCode, string $code, string $locale): string
            {
                return 'ES' === $countryCode && 'B' === $code ? 'Barcelona' : $code;
            }
        });

        self::assertSame(
            "08013 Barcelona\nBarcelona\nES",
            $formatter->format(['city' => 'Barcelona', 'state' => 'B', 'postcode' => '08013', 'country' => 'ES'], 'en_US'),
        );

        // Same resolver, a country whose format asks for the code: the resolved name is not used.
        self::assertSame(
            "Albany, NY 12207\nUS",
            $formatter->format(['city' => 'Albany', 'state' => 'NY', 'postcode' => '12207', 'country' => 'US'], 'en_US'),
        );
    }

    /** A merchant's corridor beats the shipped table, which is the only reason the table is safe to ship partial. */
    public function testAnOverrideReplacesTheShippedLayout(): void
    {
        $formatter = new PostalAddressFormatter(
            new IcuPlaceNames(),
            ['CH' => "{address_1}\n{city} {postcode}\n{country_upper}"],
        );

        self::assertSame(
            "Bahnhofstrasse 12\nGenève 1204\nSWITZERLAND",
            $formatter->format(self::SWISS, 'en_US'),
        );
    }

    /**
     * `CH:GE` is how WooCommerce stores a store's base country, so that shape circulates wherever a
     * host address is read. Callers that split it first lose nothing; one that does not gets the
     * same answer rather than the fallback layout and a country line reading `CH:GE`.
     *
     * {@see \Nandan108\InvFlux\Domain\Procurement\StreetLine::numberComesFirst()} accepts it for
     * this reason too, and the two must agree or a house number lands on the wrong side of a street
     * that was laid out correctly.
     */
    public function testTheHostsCountryColonSubdivisionShapeIsAccepted(): void
    {
        self::assertSame(
            (new PostalAddressFormatter())->format(self::SWISS, 'en_US'),
            (new PostalAddressFormatter())->format(['country' => 'ch:ge'] + self::SWISS, 'en_US'),
        );
    }

    /** An unknown region resolves to itself rather than to nothing — a code is legible, a gap is not. */
    public function testAnUnknownCountryCodeSurvivesAsItself(): void
    {
        self::assertSame('XZ', (new IcuPlaceNames())->country('XZ', 'en_US'));
        self::assertSame('', (new IcuPlaceNames())->country('', 'en_US'));
        self::assertSame('NY', (new IcuPlaceNames())->subdivision('US', 'NY', 'en_US'));
    }
}
