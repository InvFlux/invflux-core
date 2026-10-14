<?php

declare(strict_types=1);

namespace Tests\Application\Procurement;

use Nandan108\InvFlux\Application\Procurement\ReopenPurchaseOrder;
use Nandan108\InvFlux\Domain\Procurement\InvalidPurchaseOrderTransition;
use Nandan108\InvFlux\Domain\Procurement\PoEvent;
use Nandan108\InvFlux\Domain\Procurement\PoStatus;
use Nandan108\InvFlux\Domain\Procurement\PurchaseOrder;
use Nandan108\InvFlux\Domain\Procurement\PurchaseOrderLine;
use Nandan108\InvFlux\Domain\Procurement\PurchaseOrderRepository;
use PHPUnit\Framework\TestCase;

final class ReopenPurchaseOrderTest extends TestCase
{
    private ?PoEvent $event = null;

    /** A receipt corrected down, some units still in: the order goes back to partially received. */
    public function testReopensAsPartiallyReceivedWhileSomethingStaysReceived(): void
    {
        $po = PurchaseOrder::newWith(['id' => 1, 'supplier_id' => 1, 'status' => PoStatus::Received, 'received_at' => new \DateTimeImmutable('2026-10-01')]);

        $result = ($this->reopen([[3, 2]]))($po, actorId: 7, note: 'replacement on its way');

        self::assertSame(PoStatus::PartiallyReceived, $result->status);
        self::assertNull($result->received_at);
        self::assertNotNull($this->event);
        self::assertSame('po.partially_received', $this->event->event_type);
        self::assertSame(['reopened' => true], json_decode((string) $this->event->payload, true, 512, JSON_THROW_ON_ERROR));
        self::assertSame('replacement on its way', $this->event->note);
        self::assertSame(7, $this->event->actor_id);
    }

    /** The receipt was taken back entirely: the order waits for the goods again, in transit. */
    public function testReopensInTransitWhenNothingStaysReceived(): void
    {
        $po = PurchaseOrder::newWith(['id' => 1, 'supplier_id' => 1, 'status' => PoStatus::Received]);

        self::assertSame(PoStatus::InTransit, ($this->reopen([[0, 4], [0, 1]]))($po)->status);
        self::assertSame('po.in_transit', $this->event?->event_type);
    }

    public function testOnlyAReceivedOrderReopens(): void
    {
        $this->expectException(InvalidPurchaseOrderTransition::class);

        ($this->reopen([[1, 2]]))(PurchaseOrder::newWith(['id' => 1, 'supplier_id' => 1, 'status' => PoStatus::PartiallyReceived]));
    }

    public function testNothingOpenNothingToReopen(): void
    {
        $this->expectExceptionMessage(InvalidPurchaseOrderTransition::REASON_NOTHING_OPEN);

        ($this->reopen([[5, 0]]))(PurchaseOrder::newWith(['id' => 1, 'supplier_id' => 1, 'status' => PoStatus::Received]));
    }

    /** @param list<array{int, int}> $lines received, open per line */
    private function reopen(array $lines): ReopenPurchaseOrder
    {
        $repo = $this->createMock(PurchaseOrderRepository::class);
        $repo->method('linesForPurchaseOrder')->willReturn(array_map(
            static fn (array $l): PurchaseOrderLine => PurchaseOrderLine::newWith(['po_id' => 1, 'subject_id' => 1, 'qty_received' => $l[0], 'qty_open' => $l[1]]),
            $lines,
        ));
        $repo->method('transitionPurchaseOrder')->willReturnCallback(
            function (PurchaseOrder $p, PoEvent $e): PurchaseOrder {
                $this->event = $e;

                return $p;
            },
        );

        return new ReopenPurchaseOrder($repo);
    }
}
