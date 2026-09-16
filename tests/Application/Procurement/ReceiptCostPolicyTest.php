<?php

declare(strict_types=1);

namespace Tests\Application\Procurement;

use Nandan108\InvFlux\Application\Procurement\ReceiveGoods;
use Nandan108\InvFlux\Domain\Procurement\CostSource;
use Nandan108\InvFlux\Domain\Procurement\GoodsReceipt;
use Nandan108\InvFlux\Domain\Procurement\ReceiptLine;
use Nandan108\InvFlux\Domain\Procurement\ReceiptReason;
use Nandan108\InvFlux\Exceptions\ReceiptCostPolicyException;
use PHPUnit\Framework\TestCase;

/**
 * The gate that stops a receipt raised against no document from bringing stock in unvalued.
 *
 * {@see ReceiptReason::costSource()} says where each intake's cost comes from; this is where saying
 * it starts to cost something. Two of the four sources can fail before any work is done, and the
 * point of covering them here is that both failures are silent otherwise — an uncosted line still
 * moves stock perfectly well, and simply drops out of the weighted average as though it had never
 * arrived.
 */
final class ReceiptCostPolicyTest extends TestCase
{
    private const PO_REF_TYPE = 2;

    public function testAnEnteredCostReasonRefusesALineThatStatesNoCost(): void
    {
        $receipt = GoodsReceipt::newWith(['reason' => ReceiptReason::OpeningBalance]);

        try {
            ReceiveGoods::assertCostIsStatable($receipt, [self::line(subjectId: 7, qty: 10, unitCost: null)]);
            self::fail('An opening balance with no cost should have been refused.');
        } catch (ReceiptCostPolicyException $e) {
            self::assertSame(ReceiptReason::OpeningBalance, $e->reason);
            self::assertSame(CostSource::Entered, $e->costSource);
            self::assertSame(7, $e->subjectId, 'the refusal names the line that has to be answered');
        }
    }

    public function testAnEnteredCostReasonAcceptsALineThatStatesOne(): void
    {
        $receipt = GoodsReceipt::newWith(['reason' => ReceiptReason::SupplierDelivery]);

        ReceiveGoods::assertCostIsStatable($receipt, [self::line(subjectId: 7, qty: 10, unitCost: '4.5000')]);

        $this->expectNotToPerformAssertions();
    }

    /**
     * A line receiving nothing is not an intake of anything, so it owes no cost — the case that
     * matters because a receiving grid submits every line it knows about, most of them at zero.
     */
    public function testAnEnteredCostReasonIgnoresALineReceivingNothing(): void
    {
        $receipt = GoodsReceipt::newWith(['reason' => ReceiptReason::InHouseBuild]);

        ReceiveGoods::assertCostIsStatable($receipt, [self::line(subjectId: 7, qty: 0, unitCost: null)]);

        $this->expectNotToPerformAssertions();
    }

    /**
     * A transfer's cost travels with the goods, which needs somewhere for them to have travelled
     * from. While the location dimension holds a single on-hand address there is no origin to read,
     * and the honest answer is to refuse rather than substitute a standing valuation — that would
     * record a cost that never moved, and read afterwards exactly like one that did.
     */
    public function testTransferInIsRefusedWhileThereIsNoOriginToDeriveFrom(): void
    {
        $receipt = GoodsReceipt::newWith(['reason' => ReceiptReason::TransferIn]);

        try {
            ReceiveGoods::assertCostIsStatable($receipt, [self::line(subjectId: 7, qty: 10, unitCost: '4.5000')]);
            self::fail('A transfer-in should have been refused: nothing can derive its cost yet.');
        } catch (ReceiptCostPolicyException $e) {
            self::assertSame(CostSource::Derived, $e->costSource);
            self::assertNull($e->subjectId, 'the derivation fails for the whole receipt, not one line');
        }
    }

    /** @return iterable<string, array{ReceiptReason}> */
    public static function deferredCostReasons(): iterable
    {
        yield 'found stock falls back to the standing valuation' => [ReceiptReason::FoundStock];
        yield 'goods back outside the RMA process do too'        => [ReceiptReason::ReturnOutsideRma];
        yield 'a sample cost nothing, and zero is the fact'      => [ReceiptReason::SampleOrDonation];
    }

    /**
     * The reasons whose answer is settled later, under the locks — nothing to refuse up front,
     * because the number does not come from the operator.
     *
     * @dataProvider deferredCostReasons
     */
    public function testAReasonThatDoesNotAskTheOperatorPassesUnstatedLines(ReceiptReason $reason): void
    {
        $receipt = GoodsReceipt::newWith(['reason' => $reason]);

        ReceiveGoods::assertCostIsStatable($receipt, [self::line(subjectId: 7, qty: 10, unitCost: null)]);

        $this->expectNotToPerformAssertions();
    }

    /**
     * A receipt answering an order carries no reason at all: its cost is on the order, so there is no
     * policy to apply and an unpriced line is the ordering document's business, not this gate's.
     */
    public function testAReceiptAgainstAnOrderReachesNoPolicy(): void
    {
        $receipt = GoodsReceipt::newWith([
            'source_ref_type_id' => self::PO_REF_TYPE,
            'source_id'          => 42,
        ]);

        ReceiveGoods::assertCostIsStatable($receipt, [self::line(subjectId: 7, qty: 10, unitCost: null)]);

        $this->expectNotToPerformAssertions();
    }

    private static function line(int $subjectId, int $qty, ?string $unitCost): ReceiptLine
    {
        return ReceiptLine::newWith([
            'subject_id'         => $subjectId,
            'qty'                => $qty,
            'unit_cost_snapshot' => $unitCost,
        ]);
    }
}
