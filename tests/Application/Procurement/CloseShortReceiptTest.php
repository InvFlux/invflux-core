<?php

declare(strict_types=1);

namespace Tests\Application\Procurement;

use Nandan108\InvFlux\Application\Procurement\CloseShortReceipt;
use Nandan108\InvFlux\Domain\Procurement\Incoterm;
use Nandan108\InvFlux\Domain\Procurement\InvalidPurchaseOrderTransition;
use Nandan108\InvFlux\Domain\Procurement\PoEvent;
use Nandan108\InvFlux\Domain\Procurement\PoShortfall;
use Nandan108\InvFlux\Domain\Procurement\PoStatus;
use Nandan108\InvFlux\Domain\Procurement\PurchaseOrder;
use Nandan108\InvFlux\Domain\Procurement\PurchaseOrderLine;
use Nandan108\InvFlux\Domain\Procurement\PurchaseOrderRepository;
use Nandan108\InvFlux\Domain\Procurement\ShortfallCapture;
use Nandan108\InvFlux\Domain\Procurement\ShortfallCause;
use Nandan108\InvFlux\Domain\Procurement\ShortfallPart;
use Nandan108\InvFlux\Domain\Procurement\ShortfallParty;
use Nandan108\InvFlux\Exceptions\ShortfallCaptureException;
use PHPUnit\Framework\TestCase;

final class CloseShortReceiptTest extends TestCase
{
    /** @var array{lines: list<PurchaseOrderLine>, event: PoEvent, shortfalls: list<PoShortfall>}|null */
    private ?array $written = null;

    private function repo(): PurchaseOrderRepository
    {
        $repo = $this->createMock(PurchaseOrderRepository::class);
        $repo->method('closePurchaseOrderShort')->willReturnCallback(
            /**
             * @param list<PurchaseOrderLine> $lines
             * @param list<PoShortfall>       $shortfalls
             */
            function (PurchaseOrder $p, array $lines, PoEvent $e, array $shortfalls): PurchaseOrder {
                $this->written = ['lines' => $lines, 'event' => $e, 'shortfalls' => $shortfalls];

                return $p;
            },
        );

        return $repo;
    }

    /** @return array{lines: list<PurchaseOrderLine>, event: PoEvent, shortfalls: list<PoShortfall>} */
    private function written(): array
    {
        self::assertNotNull($this->written, 'the close was recorded');

        return $this->written;
    }

    public function testWritesOffOutstandingLinesAndFinalizesToReceived(): void
    {
        $po = PurchaseOrder::newWith(['id' => 1, 'supplier_id' => 1, 'status' => PoStatus::InReception]);
        // Product 1: 4 of 10 still outstanding. Product 2: fully received (untouched).
        $lines = [
            PurchaseOrderLine::newWith(['po_id' => 1, 'subject_id' => 1, 'qty_requested' => 10, 'qty_received' => 6, 'qty_open' => 4, 'qty_closed_short' => 0]),
            PurchaseOrderLine::newWith(['po_id' => 1, 'subject_id' => 2, 'qty_requested' => 5, 'qty_received' => 5, 'qty_open' => 0, 'qty_closed_short' => 0]),
        ];

        $result = (new CloseShortReceipt($this->repo()))($po, $lines, actorId: 7, reason: 'supplier_oos', note: 'pallet never shipped');

        $written = $this->written();
        // Only the outstanding line is written off, by exactly its open qty.
        self::assertCount(1, $written['lines']);
        self::assertSame(1, $written['lines'][0]->subject_id);
        self::assertSame(4, $written['lines'][0]->qty_closed_short);
        self::assertSame(0, $lines[1]->qty_closed_short, 'fully-received line is untouched');

        // Finalized to received with a short-closed event carrying the note.
        self::assertSame(PoStatus::Received, $result->status);
        self::assertNotNull($result->received_at);
        self::assertSame(PoEvent::TYPE_SHORT_CLOSED, $written['event']->event_type);
        self::assertSame('pallet never shipped', $written['event']->note);
        /** @var array{reason: string, lines: list<array{subjectId: int, qty: int}>} $payload */
        $payload = json_decode((string) $written['event']->payload, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('supplier_oos', $payload['reason']);
        self::assertSame([['subjectId' => 1, 'qty' => 4]], $payload['lines']);

        // The single reason stands in for the one part present: 4 undelivered, out of stock.
        self::assertCount(1, $written['shortfalls']);
        $row = $written['shortfalls'][0];
        self::assertSame(ShortfallPart::Undelivered, $row->part);
        self::assertSame(4, $row->qty);
        self::assertSame(ShortfallCause::OutOfStock, $row->cause);
        self::assertSame(ShortfallParty::Supplier, $row->party);
        self::assertTrue($row->party_inferred);
    }

    public function testUnknownReasonCoercesToOther(): void
    {
        $po = PurchaseOrder::newWith(['id' => 1, 'supplier_id' => 1, 'status' => PoStatus::InReception]);
        $lines = [PurchaseOrderLine::newWith(['po_id' => 1, 'subject_id' => 1, 'qty_requested' => 10, 'qty_received' => 6, 'qty_open' => 4])];

        (new CloseShortReceipt($this->repo()))($po, $lines, null, 'nonsense');

        $written = $this->written();
        /** @var array{reason: string} $payload */
        $payload = json_decode((string) $written['event']->payload, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(CloseShortReceipt::REASON_OTHER, $payload['reason']);
        // An unexplained single reason is an unknown cause, accepted without a note on this path.
        self::assertSame(ShortfallCause::Unknown, $written['shortfalls'][0]->cause);
    }

    /**
     * A line confirmed short, its confirmed units in, some damaged: the close splits what is open into
     * its parts from the counts, each with the cause and party the capture gives it — the header's
     * choice unless the line overrides it, the party suggested from the Incoterm unless one is given.
     */
    public function testSplitsTheShortfallIntoItsPartsAndRecordsWhyForEach(): void
    {
        $po = PurchaseOrder::newWith(['id' => 1, 'supplier_id' => 1, 'status' => PoStatus::InReception, 'incoterm' => Incoterm::FCA]);
        // Ordered 20, confirmed 12, 8 good + 2 damaged arrived: open 12 = 8 unconfirmed + 2 undelivered + 2 damaged.
        $lines = [
            PurchaseOrderLine::newWith(['po_id' => 1, 'subject_id' => 1, 'qty_requested' => 20, 'qty_expected' => 12, 'qty_received' => 8, 'qty_damaged' => 2, 'qty_open' => 12]),
            PurchaseOrderLine::newWith(['po_id' => 1, 'subject_id' => 2, 'qty_requested' => 6, 'qty_received' => 3, 'qty_open' => 3]),
        ];
        $capture = new ShortfallCapture(
            parts: [
                ShortfallPart::Unconfirmed->value => ['cause' => ShortfallCause::OutOfStock],
                ShortfallPart::Undelivered->value => ['cause' => ShortfallCause::ShippedShort],
                ShortfallPart::Damaged->value     => ['cause' => ShortfallCause::DamagedInTransit],
            ],
            // Product 2's missing units were our own cancellation, and we say so.
            lines: [2 => [ShortfallPart::Undelivered->value => ['cause' => ShortfallCause::BuyerCancelled, 'party' => ShortfallParty::Us]]],
        );

        (new CloseShortReceipt($this->repo()))($po, $lines, capture: $capture);

        $rows = array_map(
            static fn (PoShortfall $r): array => [$r->subject_id, $r->part->value, $r->qty, $r->cause->value, $r->party->value, $r->party_inferred],
            $this->written()['shortfalls'],
        );
        self::assertSame([
            [1, 'unconfirmed', 8, 'out_of_stock', 'supplier', true],
            [1, 'undelivered', 2, 'shipped_short', 'supplier', true],
            // FCA: risk had passed to us, so damage on the way answers to the carrier.
            [1, 'damaged', 2, 'damaged_in_transit', 'carrier', true],
            [2, 'undelivered', 3, 'buyer_cancelled', 'us', false],
        ], $rows);
        // The rows of one close add up to what it wrote off.
        self::assertSame(15, array_sum(array_column($rows, 2)));
    }

    /** A part present with no cause refuses the whole close; nothing is written. */
    public function testAPartLeftUnexplainedRefusesTheClose(): void
    {
        $po = PurchaseOrder::newWith(['id' => 1, 'supplier_id' => 1, 'status' => PoStatus::InReception]);
        $lines = [PurchaseOrderLine::newWith(['po_id' => 1, 'subject_id' => 1, 'qty_requested' => 20, 'qty_expected' => 12, 'qty_received' => 12, 'qty_open' => 8])];
        $repo = $this->createMock(PurchaseOrderRepository::class);
        $repo->expects($this->never())->method('closePurchaseOrderShort');

        try {
            (new CloseShortReceipt($repo))($po, $lines, capture: new ShortfallCapture(parts: [
                ShortfallPart::Undelivered->value => ['cause' => ShortfallCause::ShippedShort],
            ]));
            self::fail('expected a refusal');
        } catch (ShortfallCaptureException $e) {
            self::assertSame(ShortfallCaptureException::MISSING_CAUSE, $e->reasonCode);
            self::assertSame('unconfirmed', $e->part);
        }
    }

    public function testAnUnknownCauseNeedsANote(): void
    {
        $po = PurchaseOrder::newWith(['id' => 1, 'supplier_id' => 1, 'status' => PoStatus::InReception]);
        $lines = [PurchaseOrderLine::newWith(['po_id' => 1, 'subject_id' => 1, 'qty_requested' => 10, 'qty_received' => 6, 'qty_open' => 4])];
        $capture = new ShortfallCapture(parts: [ShortfallPart::Undelivered->value => ['cause' => ShortfallCause::Unknown]]);

        $this->expectException(ShortfallCaptureException::class);
        (new CloseShortReceipt($this->createStub(PurchaseOrderRepository::class)))($po, $lines, capture: $capture);
    }

    /**
     * Units left open on an order that already completed — a receipt corrected down — are written off
     * in place: the shortfall is recorded, and the order keeps its status and completion date.
     */
    public function testAReceivedOrderClosesItsRemainderWithoutReopening(): void
    {
        $completed = new \DateTimeImmutable('2026-10-01 10:00:00');
        $po = PurchaseOrder::newWith(['id' => 1, 'supplier_id' => 1, 'status' => PoStatus::Received, 'received_at' => $completed]);
        $lines = [PurchaseOrderLine::newWith(['po_id' => 1, 'subject_id' => 1, 'qty_requested' => 10, 'qty_expected' => 10, 'qty_received' => 7, 'qty_open' => 3])];
        $capture = new ShortfallCapture(parts: [ShortfallPart::Undelivered->value => ['cause' => ShortfallCause::Withheld]], note: 'held for an unpaid invoice');

        $result = (new CloseShortReceipt($this->repo()))($po, $lines, capture: $capture);

        self::assertSame(PoStatus::Received, $result->status);
        self::assertSame($completed, $result->received_at);
        $written = $this->written();
        self::assertSame(3, $written['lines'][0]->qty_closed_short);
        self::assertSame([['undelivered', 3, 'withheld', 'supplier']], array_map(
            static fn (PoShortfall $r): array => [$r->part->value, $r->qty, $r->cause->value, $r->party->value],
            $written['shortfalls'],
        ));
        /** @var array{remainder: bool} $payload */
        $payload = json_decode((string) $written['event']->payload, true, 512, JSON_THROW_ON_ERROR);
        self::assertTrue($payload['remainder']);
    }

    public function testRejectsCloseShortOutsideReception(): void
    {
        $po = PurchaseOrder::newWith(['id' => 1, 'supplier_id' => 1, 'status' => PoStatus::Submitted]);
        $repo = $this->createStub(PurchaseOrderRepository::class);

        $this->expectException(InvalidPurchaseOrderTransition::class);
        (new CloseShortReceipt($repo))($po, []);
    }
}
