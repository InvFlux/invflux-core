<?php

declare(strict_types=1);

namespace Tests\Domain\Subject;

use Nandan108\InvFlux\Contracts\Inventory\InventoryReader;
use Nandan108\InvFlux\Domain\Order\OrderLineRepository;
use Nandan108\InvFlux\Domain\Procurement\PurchaseOrderRepository;
use Nandan108\InvFlux\Domain\Subject\SubjectDeletionGuard;
use Nandan108\InvFlux\Domain\Subject\SubjectId;
use PHPUnit\Framework\TestCase;

final class SubjectDeletionGuardTest extends TestCase
{
    private const SUBJECT = 628;

    public function testCleanVerdictWhenNoInventoryOrdersOrProcurement(): void
    {
        $verdict = $this->guard(inventory: 0, orders: 0, procurement: 0)->evaluate(new SubjectId(self::SUBJECT));

        self::assertTrue($verdict->isDeletable());
        self::assertSame([], $verdict->blockers);
    }

    public function testNonZeroInventoryBlocks(): void
    {
        $verdict = $this->guard(inventory: 3, orders: 0, procurement: 0)->evaluate(new SubjectId(self::SUBJECT));

        self::assertFalse($verdict->isDeletable());
        self::assertCount(1, $verdict->blockers);
        self::assertSame(SubjectDeletionGuard::TYPE_NON_ZERO_INVENTORY, $verdict->blockers[0]->type);
        self::assertSame(3, $verdict->blockers[0]->count);
        self::assertSame(SubjectDeletionGuard::REMEDIATION_WRITE_OFF_STOCK, $verdict->blockers[0]->remediation);
    }

    public function testOpenOrderLinesBlock(): void
    {
        $verdict = $this->guard(inventory: 0, orders: 1, procurement: 0)->evaluate(new SubjectId(self::SUBJECT));

        self::assertFalse($verdict->isDeletable());
        self::assertCount(1, $verdict->blockers);
        self::assertSame(SubjectDeletionGuard::TYPE_OPEN_ORDER_LINES, $verdict->blockers[0]->type);
        self::assertSame(1, $verdict->blockers[0]->count);
        self::assertSame(SubjectDeletionGuard::REMEDIATION_CLOSE_ORDERS, $verdict->blockers[0]->remediation);
    }

    public function testOpenProcurementBlocks(): void
    {
        $verdict = $this->guard(inventory: 0, orders: 0, procurement: 2)->evaluate(new SubjectId(self::SUBJECT));

        self::assertFalse($verdict->isDeletable());
        self::assertCount(1, $verdict->blockers);
        self::assertSame(SubjectDeletionGuard::TYPE_OPEN_PROCUREMENT, $verdict->blockers[0]->type);
        self::assertSame(2, $verdict->blockers[0]->count);
        self::assertSame(SubjectDeletionGuard::REMEDIATION_CLOSE_PROCUREMENT, $verdict->blockers[0]->remediation);
    }

    public function testAllThreeBlockersReportedTogetherInOrder(): void
    {
        $verdict = $this->guard(inventory: 1, orders: 2, procurement: 3)->evaluate(new SubjectId(self::SUBJECT));

        self::assertFalse($verdict->isDeletable());
        self::assertSame(
            [
                SubjectDeletionGuard::TYPE_NON_ZERO_INVENTORY,
                SubjectDeletionGuard::TYPE_OPEN_ORDER_LINES,
                SubjectDeletionGuard::TYPE_OPEN_PROCUREMENT,
            ],
            array_map(static fn ($b): string => $b->type, $verdict->blockers),
        );
    }

    private function guard(int $inventory, int $orders, int $procurement): SubjectDeletionGuard
    {
        $inv = $this->createStub(InventoryReader::class);
        $inv->method('countNonZeroSlots')->willReturn($inventory);

        $lines = $this->createStub(OrderLineRepository::class);
        $lines->method('countOutstandingForSubject')->willReturn($orders);

        $pos = $this->createStub(PurchaseOrderRepository::class);
        $pos->method('countOpenProcurementForSubject')->willReturn($procurement);

        return new SubjectDeletionGuard($inv, $lines, $pos);
    }
}
