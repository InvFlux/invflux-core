<?php

declare(strict_types=1);

namespace Tests\Domain\Procurement;

use Nandan108\Attrecord\Exception\RecordValidationException;
use Nandan108\InvFlux\Domain\Procurement\CostSource;
use Nandan108\InvFlux\Domain\Procurement\GoodsReceipt;
use Nandan108\InvFlux\Domain\Procurement\ReceiptLine;
use Nandan108\InvFlux\Domain\Procurement\ReceiptReason;
use PHPUnit\Framework\TestCase;

/**
 * Stock arrives in two shapes, and a receipt must be unambiguously one of them: answering a document,
 * or answering nothing and saying why. The half-formed states in between are what these pin down,
 * because a receipt that is neither is a costed stock movement nobody can account for.
 */
final class ReceiptSourceTest extends TestCase
{
    private const PO_REF_TYPE = 2;

    public function testAReceiptAgainstAnOrderIsWellFormed(): void
    {
        $receipt = GoodsReceipt::newWith([
            'source_ref_type_id' => self::PO_REF_TYPE,
            'source_id'          => 42,
        ]);

        $receipt->validate();
        self::assertTrue($receipt->hasSource());
    }

    public function testASourcelessIntakeIsWellFormedWhenItSaysWhy(): void
    {
        $receipt = GoodsReceipt::newWith(['reason' => ReceiptReason::OpeningBalance]);

        $receipt->validate();
        self::assertFalse($receipt->hasSource());
    }

    /**
     * Half a reference is the shape worth refusing loudly: a type with no id points at nothing, an
     * id with no type points at everything. Neither would fail at the database, because there is no
     * foreign key to catch it.
     */
    public function testHalfASourceReferenceIsRefused(): void
    {
        $this->expectException(RecordValidationException::class);
        $this->expectExceptionMessageMatches('/half-set/');

        GoodsReceipt::newWith(['source_ref_type_id' => self::PO_REF_TYPE])->validate();
    }

    public function testAnIdWithoutARefTypeIsRefused(): void
    {
        $this->expectException(RecordValidationException::class);
        $this->expectExceptionMessageMatches('/half-set/');

        GoodsReceipt::newWith(['source_id' => 42])->validate();
    }

    /** A document already says why the goods are coming; a second answer could contradict the first. */
    public function testAReceiptAgainstAnOrderMayNotAlsoCarryAReason(): void
    {
        $this->expectException(RecordValidationException::class);

        GoodsReceipt::newWith([
            'source_ref_type_id' => self::PO_REF_TYPE,
            'source_id'          => 42,
            'reason'             => ReceiptReason::FoundStock,
        ])->validate();
    }

    /** Without a reason a source-less intake is an unexplained costed movement — the thing to prevent. */
    public function testASourcelessIntakeWithoutAReasonIsRefused(): void
    {
        $this->expectException(RecordValidationException::class);
        $this->expectExceptionMessageMatches('/must carry a reason/');

        GoodsReceipt::newWith([])->validate();
    }

    /** Zero is the old default masquerading as a real id; it must not survive the relaxation. */
    public function testZeroIdsAreNotAcceptedAsASource(): void
    {
        $this->expectException(RecordValidationException::class);

        GoodsReceipt::newWith(['source_ref_type_id' => self::PO_REF_TYPE, 'source_id' => 0])->validate();
    }

    public function testAReceiptLineMayCountAgainstNoOrderedLine(): void
    {
        $line = ReceiptLine::newWith(['subject_id' => 7, 'qty' => 3]);

        $line->validate();
        self::assertNull($line->po_line_id);
    }

    /** Nullable now, but still never zero — that would be "no line" wearing a line's clothes. */
    public function testAReceiptLineRejectsAZeroOrderedLine(): void
    {
        $this->expectException(RecordValidationException::class);
        $this->expectExceptionMessageMatches('/positive integer when set/');

        ReceiptLine::newWith(['subject_id' => 7, 'qty' => 3, 'po_line_id' => 0])->validate();
    }

    /** What arrived is never optional: a line with no subject moves stock nowhere. */
    public function testAReceiptLineStillRequiresItsSubject(): void
    {
        $this->expectException(RecordValidationException::class);

        ReceiptLine::newWith(['qty' => 3])->validate();
    }

    /**
     * The reason decides what the intake owes about cost — the point of encoding it rather than
     * treating it as a label. Cost is asked for only where someone actually knows the figure.
     */
    public function testEachReasonHasADefinedCostSource(): void
    {
        $expected = [
            ReceiptReason::OpeningBalance->value    => CostSource::Entered,
            ReceiptReason::SupplierDelivery->value  => CostSource::Entered,
            ReceiptReason::InHouseBuild->value      => CostSource::Entered,
            ReceiptReason::TransferIn->value        => CostSource::Derived,
            ReceiptReason::FoundStock->value        => CostSource::SeedFallback,
            ReceiptReason::ReturnOutsideRma->value  => CostSource::SeedFallback,
            ReceiptReason::SampleOrDonation->value  => CostSource::Free,
        ];

        foreach (ReceiptReason::cases() as $reason) {
            self::assertArrayHasKey($reason->value, $expected, 'a new reason must state its cost source');
            self::assertSame($expected[$reason->value], $reason->costSource(), $reason->value);
        }
    }

    /**
     * The two reasons that may omit a cost are exactly those where a number the operator did not
     * type is still real — carried from an origin, or genuinely zero — plus the standing-valuation
     * fallback. A reason whose whole point is that someone paid something never qualifies.
     */
    public function testOnlyDerivedOrFreeOrFallbackReasonsMayOmitCost(): void
    {
        $mayOmit = array_values(array_filter(
            ReceiptReason::cases(),
            static fn (ReceiptReason $r): bool => $r->allowsAbsentCost(),
        ));

        self::assertSame(
            [ReceiptReason::TransferIn, ReceiptReason::FoundStock, ReceiptReason::SampleOrDonation, ReceiptReason::ReturnOutsideRma],
            $mayOmit,
        );
        self::assertFalse(ReceiptReason::SupplierDelivery->allowsAbsentCost(), 'you paid for it, so say what');
    }
}
