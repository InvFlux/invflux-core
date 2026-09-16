<?php

declare(strict_types=1);

namespace Tests\Domain\Procurement;

use Nandan108\InvFlux\Domain\Procurement\Incoterm;
use Nandan108\InvFlux\Domain\Procurement\PurchaseOrder;
use PHPUnit\Framework\TestCase;

final class IncotermTest extends TestCase
{
    /** Incoterms 2020 defines eleven terms; the case list is that revision, not a preference. */
    public function testCarriesTheElevenIncoterms2020(): void
    {
        $codes = array_map(static fn (Incoterm $i): string => $i->value, Incoterm::cases());

        self::assertCount(11, $codes);
        self::assertSame($codes, array_map('strtoupper', $codes), 'the ICC writes them uppercase');
        foreach (['EXW', 'FCA', 'CPT', 'CIP', 'DAP', 'DPU', 'DDP', 'FAS', 'FOB', 'CFR', 'CIF'] as $code) {
            self::assertContains($code, $codes);
        }
    }

    /**
     * The four maritime terms are the ones routinely misapplied — FOB in particular gets written on
     * air and road orders, where it has no defined meaning — so the model can answer for a warning.
     */
    public function testMaritimeOnlyTermsAreIdentified(): void
    {
        foreach ([Incoterm::FAS, Incoterm::FOB, Incoterm::CFR, Incoterm::CIF] as $sea) {
            self::assertTrue($sea->isMaritimeOnly(), $sea->value.' is sea / inland waterway only');
        }
        foreach ([Incoterm::EXW, Incoterm::FCA, Incoterm::CPT, Incoterm::CIP, Incoterm::DAP, Incoterm::DPU, Incoterm::DDP] as $anyMode) {
            self::assertFalse($anyMode->isMaritimeOnly(), $anyMode->value.' works in any mode');
        }
    }

    /**
     * A term and the way goods travel are different questions, so a PO carries both and neither
     * defaults from the other. An order states no term until someone chooses one.
     */
    public function testAPurchaseOrderCarriesTermPlaceAndMethodSeparately(): void
    {
        $po = PurchaseOrder::newWith(['supplier_id' => 1, 'currency' => 'EUR']);
        self::assertNull($po->incoterm);
        self::assertNull($po->incoterm_place);
        self::assertNull($po->shipping_method);

        $po = PurchaseOrder::newWith([
            'supplier_id'     => 1,
            'currency'        => 'EUR',
            'incoterm'        => Incoterm::FCA,
            'incoterm_place'  => 'Rotterdam',
            'shipping_method' => 'DHL Express',
        ]);
        self::assertSame(Incoterm::FCA, $po->incoterm);
        self::assertSame('Rotterdam', $po->incoterm_place);
        self::assertSame('DHL Express', $po->shipping_method);
    }
}
