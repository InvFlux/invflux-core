<?php

declare(strict_types=1);

namespace Tests\Application\Procurement;

use Nandan108\InvFlux\Application\Procurement\TransitionPurchaseOrder;
use Nandan108\InvFlux\Domain\Procurement\InvalidPurchaseOrderTransition;
use Nandan108\InvFlux\Domain\Procurement\PoEvent;
use Nandan108\InvFlux\Domain\Procurement\PoStatus;
use Nandan108\InvFlux\Domain\Procurement\PurchaseOrder;
use Nandan108\InvFlux\Domain\Procurement\PurchaseOrderLine;
use Nandan108\InvFlux\Domain\Procurement\PurchaseOrderRepository;
use PHPUnit\Framework\TestCase;

/**
 * An order that has received anything cannot be canceled, however it got to a status with a cancel
 * edge. The edge map allows partially_received → arrived → canceled, so the use case is what stops it.
 */
final class TransitionPurchaseOrderTest extends TestCase
{
    public function testADeliveryRefusedAtTheDockCancelsWhenNothingWasReceived(): void
    {
        $result = ($this->transition(lines: [['qty_received' => 0, 'qty_damaged' => 0]]))(
            $this->po(PoStatus::Arrived),
            PoStatus::Canceled,
        );

        self::assertSame(PoStatus::Canceled, $result->status);
    }

    /** Reached through partially_received → arrived: the map allows the edge, the goods forbid it. */
    public function testAnOrderWithGoodsInCannotBeCanceledFromArrived(): void
    {
        $this->expectException(InvalidPurchaseOrderTransition::class);
        $this->expectExceptionMessage(InvalidPurchaseOrderTransition::REASON_GOODS_RECEIVED);

        ($this->transition(lines: [['qty_received' => 0, 'qty_damaged' => 0], ['qty_received' => 3, 'qty_damaged' => 0]]))(
            $this->po(PoStatus::Arrived),
            PoStatus::Canceled,
        );
    }

    /**
     * Partially received, then a delivery refused at the dock sends the order back to in_transit.
     * The goods from the first delivery are still in, so canceling from there is refused too.
     */
    public function testAnOrderWithGoodsInCannotBeCanceledAfterARefusedDelivery(): void
    {
        $this->expectException(InvalidPurchaseOrderTransition::class);
        $this->expectExceptionMessage(InvalidPurchaseOrderTransition::REASON_GOODS_RECEIVED);

        ($this->transition(lines: [['qty_received' => 4, 'qty_damaged' => 0]]))(
            $this->po(PoStatus::InTransit),
            PoStatus::Canceled,
        );
    }

    /** A refused delivery on an order with earlier deliveries in rests as partially received. */
    public function testARefusedDeliveryRestsAsPartiallyReceivedWhenSomethingCameBefore(): void
    {
        $result = ($this->transition(lines: [['qty_received' => 2, 'qty_damaged' => 0]]))(
            $this->po(PoStatus::Arrived),
            PoStatus::PartiallyReceived,
        );

        self::assertSame(PoStatus::PartiallyReceived, $result->status);
    }

    /** Nothing ever came in: partially received would claim goods that never arrived. */
    public function testARefusedDeliveryCannotRestAsPartiallyReceivedWhenNothingCame(): void
    {
        $this->expectException(InvalidPurchaseOrderTransition::class);
        $this->expectExceptionMessage(InvalidPurchaseOrderTransition::REASON_NOTHING_RECEIVED);

        ($this->transition(lines: [['qty_received' => 0, 'qty_damaged' => 0]]))(
            $this->po(PoStatus::Arrived),
            PoStatus::PartiallyReceived,
        );
    }

    /** Damaged-only earlier deliveries still came in. */
    public function testDamagedOnlyEarlierDeliveriesCountForTheRestingState(): void
    {
        $result = ($this->transition(lines: [['qty_received' => 0, 'qty_damaged' => 1]]))(
            $this->po(PoStatus::Arrived),
            PoStatus::PartiallyReceived,
        );

        self::assertSame(PoStatus::PartiallyReceived, $result->status);
    }

    /** A delivery that arrived entirely damaged still arrived: the order is no longer cancelable. */
    public function testDamagedUnitsCountAsReceived(): void
    {
        $this->expectException(InvalidPurchaseOrderTransition::class);

        ($this->transition(lines: [['qty_received' => 0, 'qty_damaged' => 2]]))(
            $this->po(PoStatus::Arrived),
            PoStatus::Canceled,
        );
    }

    /** The receipt check runs only for a cancellation; every other move keeps its usual cost. */
    public function testOtherMovesDoNotReadTheLines(): void
    {
        $repo = $this->createMock(PurchaseOrderRepository::class);
        $repo->expects(self::never())->method('linesForPurchaseOrder');
        $repo->method('transitionPurchaseOrder')->willReturnArgument(0);

        $result = (new TransitionPurchaseOrder($repo))($this->po(PoStatus::Arrived), PoStatus::InReception);

        self::assertSame(PoStatus::InReception, $result->status);
    }

    private function po(PoStatus $status): PurchaseOrder
    {
        return PurchaseOrder::newWith(['id' => 1, 'supplier_id' => 1, 'status' => $status]);
    }

    /** @param list<array{qty_received: int, qty_damaged: int}> $lines */
    private function transition(array $lines): TransitionPurchaseOrder
    {
        $repo = $this->createMock(PurchaseOrderRepository::class);
        $repo->method('linesForPurchaseOrder')->willReturn(array_map(
            static function (array $l): PurchaseOrderLine {
                $line = new PurchaseOrderLine();
                $line->qty_received = $l['qty_received'];
                $line->qty_damaged = $l['qty_damaged'];

                return $line;
            },
            $lines,
        ));
        $repo->method('transitionPurchaseOrder')->willReturnCallback(
            static fn (PurchaseOrder $p, PoEvent $e): PurchaseOrder => $p,
        );

        return new TransitionPurchaseOrder($repo);
    }
}
