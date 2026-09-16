<?php

declare(strict_types=1);

namespace Tests\Application\Procurement;

use Nandan108\InvFlux\Application\Procurement\RecordSupplierInvoice;
use Nandan108\InvFlux\Domain\Procurement\PoEvent;
use Nandan108\InvFlux\Domain\Procurement\PoStatus;
use Nandan108\InvFlux\Domain\Procurement\PurchaseOrder;
use Nandan108\InvFlux\Domain\Procurement\PurchaseOrderLine;
use Nandan108\InvFlux\Domain\Procurement\PurchaseOrderRepository;
use Nandan108\InvFlux\Domain\Procurement\SupplierInvoice;
use Nandan108\InvFlux\Domain\Procurement\SupplierInvoiceLine;
use Nandan108\InvFlux\Exceptions\InvalidSupplierInvoiceException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class RecordSupplierInvoiceTest extends TestCase
{
    /** The ordered lines the use case handed back for saving, captured by {@see repo()}. */
    /** @var list<PurchaseOrderLine> */
    private array $touched = [];

    private ?PoEvent $event = null;

    private function po(): PurchaseOrder
    {
        return PurchaseOrder::newWith([
            'id' => 1, 'supplier_id' => 1, 'status' => PoStatus::InTransit, 'currency' => 'EUR',
        ]);
    }

    private function orderLine(int $id, string $unitCost = '10.0000'): PurchaseOrderLine
    {
        return PurchaseOrderLine::newWith([
            'id'        => $id, 'po_id' => 1, 'subject_id' => $id, 'qty_requested' => 100,
            'unit_cost' => $unitCost,
        ]);
    }

    private function invoiceLine(int $poLineId, int $qty, string $unitCost): SupplierInvoiceLine
    {
        return SupplierInvoiceLine::newWith([
            'po_line_id' => $poLineId, 'qty' => $qty, 'unit_cost' => $unitCost,
        ]);
    }

    /**
     * @param list<PurchaseOrderLine>               $orderLines
     * @param array<int, list<SupplierInvoiceLine>> $prior
     */
    private function repo(array $orderLines, array $prior = []): PurchaseOrderRepository & MockObject
    {
        $repo = $this->createMock(PurchaseOrderRepository::class);
        // PoEvent is final, so the mock cannot generate a return value for this one.
        $repo->method('recordPoEvent')->willReturnCallback(
            function (PoEvent $e): PoEvent {
                $this->event = $e;

                return $e;
            },
        );
        $repo->method('linesForPurchaseOrder')->willReturn($orderLines);
        $repo->method('supplierInvoiceLinesForOrderLines')->willReturn($prior);
        $repo->method('recordSupplierInvoice')->willReturnCallback(
            /** @param list<PurchaseOrderLine> $t */
            function (SupplierInvoice $inv, array $lines, array $t): SupplierInvoice {
                $this->touched = $t;
                $inv->id = 900;

                return $inv;
            },
        );

        return $repo;
    }

    /**
     * The heart of it: a second invoice at a different price **replaces** the rate rather than
     * averaging it, while the billed quantity accumulates. Averaging here would value both
     * deliveries at a price neither was bought at — the blend belongs downstream, across the frozen
     * per-receipt snapshots.
     */
    public function testTheRateIsReplacedWhileTheQuantityAccumulates(): void
    {
        $line = $this->orderLine(10);
        $repo = $this->repo([$line], [10 => [$this->invoiceLine(10, 60, '10.5000')]]);

        (new RecordSupplierInvoice($repo))(
            $this->po(),
            SupplierInvoice::newWith(['reference' => 'INV-2', 'currency' => 'EUR']),
            [$this->invoiceLine(10, 40, '11.0000')],
            actorId: 7,
        );

        self::assertSame('11.0000', $this->touched[0]->unit_cost_invoiced, 'the latest rate, never a blend');
        self::assertSame(100, $this->touched[0]->qty_invoiced, '60 already billed + 40 now');
    }

    /** The quantity is re-summed from the documents, so it cannot drift from what justifies it. */
    public function testTheBilledQuantityIsSummedFromDocumentsNotIncremented(): void
    {
        $line = $this->orderLine(10);
        $line->qty_invoiced = 999; // a stale cache the recompute must ignore
        $repo = $this->repo([$line], [10 => [$this->invoiceLine(10, 5, '10.0000')]]);

        (new RecordSupplierInvoice($repo))(
            $this->po(),
            SupplierInvoice::newWith(['reference' => 'INV-1', 'currency' => 'EUR']),
            [$this->invoiceLine(10, 3, '10.0000')],
        );

        self::assertSame(8, $this->touched[0]->qty_invoiced);
    }

    /** A correction inside one document: the last line stated is what the supplier means. */
    public function testTheLastLineOfOneInvoiceWinsForThatOrderLine(): void
    {
        $repo = $this->repo([$this->orderLine(10)]);

        (new RecordSupplierInvoice($repo))(
            $this->po(),
            SupplierInvoice::newWith(['reference' => 'INV-3', 'currency' => 'EUR']),
            [$this->invoiceLine(10, 5, '9.0000'), $this->invoiceLine(10, 5, '9.5000')],
        );

        self::assertSame('9.5000', $this->touched[0]->unit_cost_invoiced);
        self::assertSame(10, $this->touched[0]->qty_invoiced);
    }

    /**
     * A stated currency is kept: an invoice raised in another currency than the order is a fact, not
     * an error. The record refuses to exist without one, so there is no case where this is guessed.
     */
    public function testAStatedCurrencySurvives(): void
    {
        $repo = $this->repo([$this->orderLine(10)]);

        $saved = (new RecordSupplierInvoice($repo))(
            $this->po(),
            SupplierInvoice::newWith(['reference' => 'INV-5', 'currency' => 'USD']),
            [$this->invoiceLine(10, 1, '10.0000')],
        );

        self::assertSame('USD', $saved->currency);
    }

    public function testBillingALineTheOrderDoesNotHaveIsRefused(): void
    {
        $repo = $this->repo([$this->orderLine(10)]);
        $repo->expects(self::never())->method('recordSupplierInvoice');

        $this->expectException(InvalidSupplierInvoiceException::class);
        (new RecordSupplierInvoice($repo))(
            $this->po(),
            SupplierInvoice::newWith(['reference' => 'INV-6', 'currency' => 'EUR']),
            [$this->invoiceLine(99, 1, '10.0000')],
        );
    }

    public function testAnInvoiceWithNoLinesIsRefused(): void
    {
        $repo = $this->repo([$this->orderLine(10)]);

        $this->expectException(InvalidSupplierInvoiceException::class);
        (new RecordSupplierInvoice($repo))(
            $this->po(),
            SupplierInvoice::newWith(['reference' => 'INV-7', 'currency' => 'EUR']),
            [],
        );
    }

    /**
     * Recording a charge must not touch the order's lifecycle: financial facts are a projection of
     * operational ones and never drive them.
     */
    public function testRecordingAnInvoiceMovesNoStatusAndEmitsItsOwnEvent(): void
    {
        $po = $this->po();
        $repo = $this->repo([$this->orderLine(10)]);
        $repo->expects(self::never())->method('transitionPurchaseOrder');

        (new RecordSupplierInvoice($repo))(
            $po,
            SupplierInvoice::newWith(['reference' => 'INV-8', 'currency' => 'EUR']),
            [$this->invoiceLine(10, 1, '10.0000')],
            actorId: 7,
        );

        self::assertSame(PoStatus::InTransit, $po->status);
        $event = $this->event;
        self::assertInstanceOf(PoEvent::class, $event);
        self::assertSame(RecordSupplierInvoice::EVENT_RECORDED, $event->event_type);
        self::assertSame('INV-8', $event->note);
    }

    /** The fallback valuation reads: invoiced when known, ordered otherwise — never the reverse. */
    public function testEffectiveUnitCostPrefersTheInvoicedRate(): void
    {
        $line = $this->orderLine(10, '10.0000');
        self::assertSame('10.0000', $line->effectiveUnitCost(), 'ordered price until an invoice says otherwise');

        $line->unit_cost_invoiced = '11.2500';
        self::assertSame('11.2500', $line->effectiveUnitCost());
        self::assertSame('10.0000', $line->unit_cost, 'the agreed price is never overwritten');
    }
}
