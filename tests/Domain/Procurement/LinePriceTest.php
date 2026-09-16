<?php

declare(strict_types=1);

namespace Tests\Domain\Procurement;

use Nandan108\Attrecord\Exception\RecordValidationException;
use Nandan108\InvFlux\Domain\Procurement\LinePrice;
use Nandan108\InvFlux\Domain\Procurement\PurchaseOrderLine;
use Nandan108\InvFlux\Domain\Procurement\SupplierInvoiceLine;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class LinePriceTest extends TestCase
{
    /** @return iterable<string, array{string, string, int, string}> */
    public static function nets(): iterable
    {
        yield 'a round discount'                         => ['20.0000', '10', 2, '18.0000'];
        yield 'no discount at all'                       => ['20', '0', 2, '20.0000'];
        yield "rounds half-up at the supplier's decimals" => ['10.50', '3', 2, '10.1900'];
        yield 'the same discount priced to four places'  => ['10.50', '3', 4, '10.1850'];
        yield 'a supplier pricing in whole units'        => ['1999', '12.5', 0, '1749.0000'];
        yield 'rounds half-up at the 4th place'          => ['19.99', '12.5', 4, '17.4913'];
        yield 'a half unit rounds up'                    => ['0.0001', '50', 4, '0.0001'];
        yield 'just under a half rounds down'            => ['0.0001', '50.01', 4, '0.0000'];
        yield 'fractional percent'                       => ['123.4567', '2.25', 4, '120.6789'];
        yield 'never finer than the column'              => ['10.50', '3', 6, '10.1850'];
        yield 'the largest price the column holds'       => ['999999.9999', '0.01', 4, '999899.9999'];
    }

    #[DataProvider('nets')]
    public function testTheNetIsTheListLessTheDiscountPerUnit(string $list, string $pct, int $decimals, string $net): void
    {
        self::assertSame($net, LinePrice::netOf($list, $pct, $decimals));
    }

    public function testADiscountOfAHundredPercentIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        LinePrice::netOf('10', '100', 2);
    }

    public function testANegativeOrNonNumericFigureIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        LinePrice::netOf('-10', '5', 2);
    }

    public function testATypedNetReplacesTheDiscount(): void
    {
        $price = (new LinePrice('18.0000', '20.0000', '10.00'))->withNet('17.5');

        self::assertEquals(new LinePrice('17.5000'), $price);
    }

    public function testADiscountComesOffTheLinesOwnPriceWhenNoneIsStated(): void
    {
        $price = (new LinePrice('20.0000'))->withDiscount('10', 2);

        self::assertEquals(new LinePrice('18.0000', '20.0000', '10.00'), $price);
    }

    public function testADiscountComesOffTheListPriceStatedWithIt(): void
    {
        $price = (new LinePrice('20.0000'))->withDiscount('10', 2, '30');

        self::assertEquals(new LinePrice('27.0000', '30.0000', '10.00'), $price);
    }

    /** The net is a price the supplier could have quoted: rounded to their decimals, not the column's. */
    public function testTheNetIsRoundedToTheSuppliersDecimals(): void
    {
        self::assertEquals(new LinePrice('10.1900', '10.5000', '3.00'), (new LinePrice('10.50'))->withDiscount('3', 2));
        self::assertEquals(new LinePrice('10.1850', '10.5000', '3.00'), (new LinePrice('10.50'))->withDiscount('3', 4));
    }

    public function testChangingTheDiscountKeepsTheListPriceNotTheOldNet(): void
    {
        $price = (new LinePrice('18.0000', '20.0000', '10.00'))->withDiscount('5', 2);

        self::assertEquals(new LinePrice('19.0000', '20.0000', '5.00'), $price);
    }

    public function testAnUnpricedLineTakesItsDiscountOffTheInheritedPrice(): void
    {
        $price = (new LinePrice(null))->withDiscount('10', 2, null, '12.00');

        self::assertEquals(new LinePrice('10.8000', '12.0000', '10.00'), $price);
    }

    public function testADiscountWithNoPriceAnywhereIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new LinePrice(null))->withDiscount('10', 2);
    }

    public function testClearingTheDiscountLeavesTheListPriceAsTheNet(): void
    {
        $price = (new LinePrice('18.0000', '20.0000', '10.00'))->withDiscount(null, 2);

        self::assertEquals(new LinePrice('20.0000'), $price);
    }

    public function testClearingADiscountTheLineNeverHadChangesNothing(): void
    {
        self::assertEquals(new LinePrice(null), (new LinePrice(null))->withDiscount(null, 2));
        self::assertEquals(new LinePrice('7.0000'), (new LinePrice('7.0000'))->withDiscount(null, 2));
    }

    public function testAListPriceKeepsTheDiscountAndRecomputesTheNet(): void
    {
        $price = (new LinePrice('18.0000', '20.0000', '10.00'))->withList('40', 2);

        self::assertEquals(new LinePrice('36.0000', '40.0000', '10.00'), $price);
    }

    public function testAListPriceWithNoDiscountIsSimplyThePrice(): void
    {
        self::assertEquals(new LinePrice('40.0000'), (new LinePrice('18.0000'))->withList('40', 2));
    }

    public function testRemovingTheListPriceDropsTheDiscountAndKeepsTheNet(): void
    {
        $price = (new LinePrice('18.0000', '20.0000', '10.00'))->withList(null, 2);

        self::assertEquals(new LinePrice('18.0000'), $price);
    }

    public function testCoherenceIsJudgedAtTheColumnsScale(): void
    {
        self::assertTrue(LinePrice::isCoherent('18', '20', '10'));
        self::assertTrue(LinePrice::isCoherent('18.0000', null, null));
        self::assertFalse(LinePrice::isCoherent('17.0000', '20.0000', '10.00'), 'a net that is not the computed one');
        self::assertFalse(LinePrice::isCoherent('18.0000', '20.0000', null), 'a list price without its discount');
        self::assertFalse(LinePrice::isCoherent('18.0000', null, '10.00'), 'a discount without its list price');
    }

    /**
     * A line priced at the supplier's precision stays valid when the supplier's precision changes
     * later, so coherence accepts the net at any precision the column can hold — and still refuses a
     * net no precision produces.
     */
    public function testCoherenceAcceptsTheNetAtAnyPrecision(): void
    {
        self::assertTrue(LinePrice::isCoherent('10.19', '10.50', '3'));
        self::assertTrue(LinePrice::isCoherent('10.1850', '10.50', '3'));
        self::assertTrue(LinePrice::isCoherent('10', '10.50', '3'));
        self::assertFalse(LinePrice::isCoherent('10.18', '10.50', '3'));
    }

    public function testAnOrderLineRefusesAnIncoherentPrice(): void
    {
        // Validated as it is built — an incoherent line never exists, let alone reaches a save.
        $this->expectException(RecordValidationException::class);
        PurchaseOrderLine::newWith([
            'po_id'          => 1, 'subject_id' => 1, 'unit_cost' => '17.0000',
            'list_unit_cost' => '20.0000', 'discount_pct' => '10.00',
        ]);
    }

    public function testAnOrderLineStoresAPriceReachedThroughADiscount(): void
    {
        $line = PurchaseOrderLine::newWith(['po_id' => 1, 'subject_id' => 1, 'unit_cost' => '20.0000']);
        $line->applyPrice($line->price()->withDiscount('10', 2));
        $line->validate();

        self::assertSame(['18.0000', '20.0000', '10.00'], [$line->unit_cost, $line->list_unit_cost, $line->discount_pct]);
    }

    public function testAnInvoiceLineRefusesAnIncoherentPrice(): void
    {
        $this->expectException(RecordValidationException::class);
        SupplierInvoiceLine::newWith([
            'invoice_id' => 1, 'po_line_id' => 1, 'qty' => 1, 'unit_cost' => '18.0000', 'discount_pct' => '10.00',
        ]);
    }
}
