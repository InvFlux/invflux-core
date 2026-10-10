<?php

declare(strict_types=1);

namespace Tests\Domain\Procurement;

use Nandan108\Attrecord\Exception\RecordValidationException;
use Nandan108\InvFlux\Domain\Procurement\ReceiptReason;
use Nandan108\InvFlux\Domain\Procurement\ReceivingSession;
use PHPUnit\Framework\TestCase;

/**
 * A count in progress takes the same two shapes as the receipt it will become: answering a document,
 * or answering nothing and saying why. The reason is owed from the start, because it decides what
 * each staged line owes about cost.
 */
final class ReceivingSessionTest extends TestCase
{
    private const PO_REF_TYPE = 2;

    public function testASessionAgainstAnOrderIsWellFormed(): void
    {
        $session = ReceivingSession::newWith(['source_ref_type_id' => self::PO_REF_TYPE, 'source_id' => 42]);

        $session->validate();
        self::assertTrue($session->hasSource());
    }

    public function testASourcelessSessionIsWellFormedWhenItSaysWhy(): void
    {
        $session = ReceivingSession::newWith(['reason' => ReceiptReason::FoundStock]);

        $session->validate();
        self::assertFalse($session->hasSource());
    }

    /** Without a reason, nobody could say whether its lines owe a cost — so the count cannot start. */
    public function testASourcelessSessionWithoutAReasonIsRefused(): void
    {
        $this->expectException(RecordValidationException::class);
        $this->expectExceptionMessageMatches('/must carry a reason/');

        ReceivingSession::newWith([])->validate();
    }

    /** A document already says why the goods are coming; a second answer could contradict the first. */
    public function testASessionAgainstAnOrderMayNotAlsoCarryAReason(): void
    {
        $this->expectException(RecordValidationException::class);
        $this->expectExceptionMessageMatches('/belongs to a source-less intake/');

        ReceivingSession::newWith([
            'source_ref_type_id' => self::PO_REF_TYPE,
            'source_id'          => 42,
            'reason'             => ReceiptReason::OpeningBalance,
        ])->validate();
    }

    /** A supplier is who delivered an unordered delivery; on any other count it names nobody. */
    public function testOnlyAPurchaseWithoutOrderNamesASupplier(): void
    {
        ReceivingSession::newWith(['reason' => ReceiptReason::PurchaseWithoutOrder, 'supplier_id' => 3])->validate();

        $this->expectException(RecordValidationException::class);
        $this->expectExceptionMessageMatches('/supplier_id/');

        ReceivingSession::newWith(['reason' => ReceiptReason::FoundStock, 'supplier_id' => 3])->validate();
    }

    public function testHalfASourceReferenceIsRefused(): void
    {
        $this->expectException(RecordValidationException::class);
        $this->expectExceptionMessageMatches('/half-set/');

        ReceivingSession::newWith(['source_ref_type_id' => self::PO_REF_TYPE, 'reason' => ReceiptReason::FoundStock])->validate();
    }
}
