<?php

declare(strict_types=1);

namespace Tests\Application\Procurement;

use Nandan108\InvFlux\Application\Procurement\CloseShortReceipt;
use Nandan108\InvFlux\Domain\Procurement\InvalidPurchaseOrderTransition;
use Nandan108\InvFlux\Domain\Procurement\PoEvent;
use Nandan108\InvFlux\Domain\Procurement\PoStatus;
use Nandan108\InvFlux\Domain\Procurement\PurchaseOrder;
use Nandan108\InvFlux\Domain\Procurement\PurchaseOrderLine;
use Nandan108\InvFlux\Domain\Procurement\PurchaseOrderRepository;
use PHPUnit\Framework\TestCase;

final class CloseShortReceiptTest extends TestCase
{
    public function testWritesOffOutstandingLinesAndFinalizesToReceived(): void
    {
        $po = PurchaseOrder::newWith(['id' => 1, 'supplier_id' => 1, 'status' => PoStatus::InReception]);
        // Line 10: 4 of 10 still outstanding. Line 11: fully received (untouched).
        $lines = [
            PurchaseOrderLine::newWith(['id' => 10, 'po_id' => 1, 'subject_id' => 1, 'qty_requested' => 10, 'qty_received' => 6, 'qty_open' => 4, 'qty_closed_short' => 0]),
            PurchaseOrderLine::newWith(['id' => 11, 'po_id' => 1, 'subject_id' => 2, 'qty_requested' => 5, 'qty_received' => 5, 'qty_open' => 0, 'qty_closed_short' => 0]),
        ];

        $savedLines = null;
        $repo = $this->createMock(PurchaseOrderRepository::class);
        $repo->method('savePurchaseOrderLines')->willReturnCallback(
            function (array $l) use (&$savedLines): array {
                $savedLines = $l;

                return $l;
            },
        );
        $event = null;
        $repo->method('transitionPurchaseOrder')->willReturnCallback(
            function (PurchaseOrder $p, PoEvent $e) use (&$event): PurchaseOrder {
                $event = $e;

                return $p;
            },
        );

        $result = (new CloseShortReceipt($repo))($po, $lines, actorId: 7, reason: 'supplier_oos', note: 'pallet never shipped');

        // Only the outstanding line is written off, by exactly its open qty.
        self::assertIsArray($savedLines);
        self::assertCount(1, $savedLines);
        self::assertSame(10, $savedLines[0]->id);
        self::assertSame(4, $savedLines[0]->qty_closed_short);
        self::assertSame(0, $lines[1]->qty_closed_short, 'fully-received line is untouched');

        // Finalized to received with a short-closed event.
        self::assertSame(PoStatus::Received, $result->status);
        self::assertNotNull($result->received_at);
        self::assertInstanceOf(PoEvent::class, $event);
        self::assertSame(PoEvent::TYPE_SHORT_CLOSED, $event->event_type);
        // Free-text detail lives on the event note; the structured reason lives in the payload.
        self::assertSame('pallet never shipped', $event->note);

        /** @var array{reason: string, lines: list<array{po_line_id: int, qty: int}>} $payload */
        $payload = json_decode((string) $event->payload, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('supplier_oos', $payload['reason']);
        self::assertSame([['po_line_id' => 10, 'qty' => 4]], $payload['lines']);
    }

    public function testUnknownReasonCoercesToOther(): void
    {
        $po = PurchaseOrder::newWith(['id' => 1, 'supplier_id' => 1, 'status' => PoStatus::InReception]);
        $lines = [PurchaseOrderLine::newWith(['id' => 10, 'po_id' => 1, 'subject_id' => 1, 'qty_requested' => 10, 'qty_received' => 6, 'qty_open' => 4])];

        $repo = $this->createMock(PurchaseOrderRepository::class);
        $repo->method('savePurchaseOrderLines')->willReturnArgument(0);
        $event = null;
        $repo->method('transitionPurchaseOrder')->willReturnCallback(
            function (PurchaseOrder $p, PoEvent $e) use (&$event): PurchaseOrder {
                $event = $e;

                return $p;
            },
        );

        (new CloseShortReceipt($repo))($po, $lines, null, 'nonsense');

        self::assertInstanceOf(PoEvent::class, $event);
        /** @var array{reason: string} $payload */
        $payload = json_decode((string) $event->payload, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(CloseShortReceipt::REASON_OTHER, $payload['reason']);
    }

    public function testRejectsCloseShortOutsideReception(): void
    {
        $po = PurchaseOrder::newWith(['id' => 1, 'supplier_id' => 1, 'status' => PoStatus::Submitted]);
        $repo = $this->createStub(PurchaseOrderRepository::class);

        $this->expectException(InvalidPurchaseOrderTransition::class);
        (new CloseShortReceipt($repo))($po, []);
    }
}
