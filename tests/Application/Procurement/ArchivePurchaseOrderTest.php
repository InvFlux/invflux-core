<?php

declare(strict_types=1);

namespace Tests\Application\Procurement;

use Nandan108\InvFlux\Application\Procurement\ArchivePurchaseOrder;
use Nandan108\InvFlux\Domain\Procurement\PoEvent;
use Nandan108\InvFlux\Domain\Procurement\PoStatus;
use Nandan108\InvFlux\Domain\Procurement\PurchaseOrder;
use Nandan108\InvFlux\Domain\Procurement\PurchaseOrderRepository;
use PHPUnit\Framework\TestCase;

final class ArchivePurchaseOrderTest extends TestCase
{
    /** @param-out ?PoEvent $event */
    private function repo(?PoEvent &$event): PurchaseOrderRepository
    {
        $repo = $this->createMock(PurchaseOrderRepository::class);
        $repo->method('transitionPurchaseOrder')->willReturnCallback(
            function (PurchaseOrder $p, PoEvent $e) use (&$event): PurchaseOrder {
                $event = $e;

                return $p;
            },
        );

        return $repo;
    }

    /**
     * The whole point of the column: filing an order away must not overwrite how it turned out, or
     * a received order becomes indistinguishable from one that was abandoned.
     */
    public function testFilingAwayLeavesTheStatusAlone(): void
    {
        $po = PurchaseOrder::newWith(['id' => 1, 'supplier_id' => 1, 'status' => PoStatus::Received]);
        $event = null;

        $result = (new ArchivePurchaseOrder($this->repo($event)))($po, true, actorId: 7);

        self::assertSame(PoStatus::Received, $result->status, 'the outcome survives being filed away');
        self::assertTrue($result->isArchived());
        self::assertNotNull($result->archived_at);
        self::assertInstanceOf(PoEvent::class, $event);
        self::assertSame(ArchivePurchaseOrder::EVENT_ARCHIVED, $event->event_type);
        self::assertSame(7, $event->actor_id);
    }

    /**
     * Reachable from every state, including ones no lifecycle edge leads out of:
     * `partially_received` has exactly two edges, and a status-shaped archive could not be one of
     * them without somebody adding it there by hand.
     */
    public function testAnyStateCanBeFiledAwayIncludingPartiallyReceived(): void
    {
        foreach (PoStatus::cases() as $status) {
            $po = PurchaseOrder::newWith(['id' => 1, 'supplier_id' => 1, 'status' => $status]);
            $event = null;

            $result = (new ArchivePurchaseOrder($this->repo($event)))($po, true);

            self::assertTrue($result->isArchived(), $status->name.' can be filed away');
            self::assertSame($status, $result->status, $status->name.' keeps its status');
        }
    }

    public function testPuttingItBackClearsTheStampAndRecordsIt(): void
    {
        $po = PurchaseOrder::newWith([
            'id'          => 1, 'supplier_id' => 1, 'status' => PoStatus::Cancelled,
            'archived_at' => new \DateTimeImmutable('-2 days'),
        ]);
        $event = null;

        $result = (new ArchivePurchaseOrder($this->repo($event)))($po, false);

        self::assertFalse($result->isArchived());
        self::assertNull($result->archived_at);
        self::assertInstanceOf(PoEvent::class, $event);
        self::assertSame(ArchivePurchaseOrder::EVENT_UNARCHIVED, $event->event_type);
    }

    /**
     * Asking for the state it is already in writes nothing — the trail says when the order was put
     * away, not how many times somebody pressed the button, and the original timestamp stands.
     */
    public function testAskingForTheCurrentStateIsANoOp(): void
    {
        $stamped = new \DateTimeImmutable('-2 days');
        $po = PurchaseOrder::newWith([
            'id' => 1, 'supplier_id' => 1, 'status' => PoStatus::Received, 'archived_at' => $stamped,
        ]);

        $repo = $this->createMock(PurchaseOrderRepository::class);
        $repo->expects(self::never())->method('transitionPurchaseOrder');

        $result = (new ArchivePurchaseOrder($repo))($po, true);

        self::assertSame($stamped, $result->archived_at, 'the original stamp is not refreshed');
    }

    public function testPuttingBackAnOrderThatIsNotFiledAwayIsANoOp(): void
    {
        $po = PurchaseOrder::newWith(['id' => 1, 'supplier_id' => 1, 'status' => PoStatus::InTransit]);

        $repo = $this->createMock(PurchaseOrderRepository::class);
        $repo->expects(self::never())->method('transitionPurchaseOrder');

        self::assertFalse((new ArchivePurchaseOrder($repo))($po, false)->isArchived());
    }
}
