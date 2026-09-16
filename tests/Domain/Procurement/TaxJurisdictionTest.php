<?php

declare(strict_types=1);

namespace Tests\Domain\Procurement;

use Nandan108\InvFlux\Domain\Procurement\TaxIdScheme;
use Nandan108\InvFlux\Domain\Procurement\TaxJurisdiction;
use Nandan108\InvFlux\Domain\Procurement\TaxKind;
use PHPUnit\Framework\TestCase;

final class TaxJurisdictionTest extends TestCase
{
    /** The default exists because most of the world has a VAT, not because EU-shaped is assumed. */
    public function testUnlistedCountriesFallBackToTheValueAddedTaxPattern(): void
    {
        foreach (['DE', 'FR', 'GB', 'NO', 'ZA', 'AR', 'KE'] as $country) {
            self::assertSame(TaxIdScheme::Vat, TaxJurisdiction::idScheme($country), $country);
            self::assertSame(TaxKind::Vat, TaxJurisdiction::taxKind($country), $country);
        }
    }

    /** The US is the case the "VAT / GST" label excluded outright: no VAT, and a state-level sales tax. */
    public function testTheUnitedStatesChargesSalesTaxAgainstAnEin(): void
    {
        self::assertSame(TaxIdScheme::Ein, TaxJurisdiction::idScheme('US'));
        self::assertSame(TaxKind::SalesTax, TaxJurisdiction::taxKind('US'));
    }

    /**
     * The identifier's scheme and the tax's name are independent axes, so the map is two maps.
     * Russia registers an INN and charges a VAT; Japan registers an invoice number and charges a
     * consumption tax; India does name both after its GST.
     */
    public function testIdentifierSchemeAndTaxNameAreIndependent(): void
    {
        self::assertSame(TaxIdScheme::Inn, TaxJurisdiction::idScheme('RU'));
        self::assertSame(TaxKind::Vat, TaxJurisdiction::taxKind('RU'));

        self::assertSame(TaxIdScheme::JapaneseRegistration, TaxJurisdiction::idScheme('JP'));
        self::assertSame(TaxKind::ConsumptionTax, TaxJurisdiction::taxKind('JP'));

        self::assertSame(TaxIdScheme::Gstin, TaxJurisdiction::idScheme('IN'));
        self::assertSame(TaxKind::Gst, TaxJurisdiction::taxKind('IN'));
    }

    /**
     * Canada charges a GST in some provinces and a harmonized sales tax in others, so it is not
     * India's GST and cannot borrow its label — 13% in Ontario is HST, not GST.
     */
    public function testCanadaIsItsOwnTaxKindRatherThanAGst(): void
    {
        self::assertSame(TaxKind::GstHst, TaxJurisdiction::taxKind('CA'));
        self::assertSame(TaxKind::Gst, TaxJurisdiction::taxKind('IN'));
        self::assertSame(TaxIdScheme::Bn, TaxJurisdiction::idScheme('CA'));
    }

    /** Brazil levies five taxes at once, so naming one on our document would name the wrong one. */
    public function testBrazilIdentifiesByCnpjAndNamesNoSingleTax(): void
    {
        self::assertSame(TaxIdScheme::Cnpj, TaxJurisdiction::idScheme('BR'));
        self::assertSame(TaxKind::Unspecified, TaxJurisdiction::taxKind('BR'));
    }

    /** WooCommerce stores the store country as `CC` or `CC:SUBDIVISION`; both must resolve alike. */
    public function testASubdivisionSuffixIsIgnored(): void
    {
        self::assertSame(TaxIdScheme::Uid, TaxJurisdiction::idScheme('CH:GE'));
        self::assertSame(TaxIdScheme::Uid, TaxJurisdiction::idScheme('CH'));
        self::assertSame(TaxKind::SalesTax, TaxJurisdiction::taxKind('US:CA'));
    }

    /** A party whose country was never recorded still has to print something. */
    public function testAnAbsentOrBlankCountryResolvesToTheDefault(): void
    {
        self::assertSame(TaxIdScheme::Vat, TaxJurisdiction::idScheme(null));
        self::assertSame(TaxIdScheme::Vat, TaxJurisdiction::idScheme(''));
        self::assertSame(TaxKind::Vat, TaxJurisdiction::taxKind(null));
    }

    /** Lower-cased input reaches us from imports and hand-edited settings alike. */
    public function testLookupIsCaseInsensitive(): void
    {
        self::assertSame(TaxIdScheme::Cnpj, TaxJurisdiction::idScheme('br'));
        self::assertSame(TaxKind::Gst, TaxJurisdiction::taxKind(' au '));
    }

    /**
     * An acronym is the same word in every language, so only the schemes whose name is a translated
     * word withhold one — those are the labels the presentation layer has to produce itself.
     */
    public function testOnlyTranslatableSchemesWithholdAnAcronym(): void
    {
        $withoutAcronym = array_values(array_filter(
            TaxIdScheme::cases(),
            static fn (TaxIdScheme $s): bool => null === $s->acronym(),
        ));

        self::assertSame(
            [TaxIdScheme::Vat, TaxIdScheme::JapaneseRegistration, TaxIdScheme::Generic],
            $withoutAcronym,
        );
        self::assertSame('CNPJ', TaxIdScheme::Cnpj->acronym());
    }
}
