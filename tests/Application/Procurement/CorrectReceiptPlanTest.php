<?php

declare(strict_types=1);

namespace Tests\Application\Procurement;

use Nandan108\InvFlux\Application\Procurement\CorrectReceipt;
use Nandan108\InvFlux\Application\Procurement\PlannedReceiptCorrection;
use Nandan108\InvFlux\Application\Procurement\ReceiptLineChange;
use Nandan108\InvFlux\Domain\Procurement\GoodsReceipt;
use Nandan108\InvFlux\Domain\Procurement\ReceiptLine;
use Nandan108\InvFlux\Domain\Procurement\SubjectCost;
use Nandan108\InvFlux\Exceptions\ReceiptCorrectionException;
use PHPUnit\Framework\TestCase;

/**
 * How a receipt correction is worked out before anything is written: which lines change, what each
 * product's stock and average do, and what is refused.
 */
final class CorrectReceiptPlanTest extends TestCase
{
    private const JEANS = 7;
    private const BROOCH = 8;

    private function receipt(?string $fxRate = null): GoodsReceipt
    {
        $receipt = new GoodsReceipt();
        $receipt->id = 128;
        $receipt->fx_rate = $fxRate;

        return $receipt;
    }

    /** @return array<int, ReceiptLine> */
    private function lines(): array
    {
        $jeans = new ReceiptLine();
        $jeans->id = 1;
        $jeans->subject_id = self::JEANS;
        $jeans->po_id = 40;
        $jeans->qty = 10;
        $jeans->unit_cost_snapshot = '10.0000';

        $brooch = new ReceiptLine();
        $brooch->id = 2;
        $brooch->subject_id = self::BROOCH;
        $brooch->qty = 5;
        $brooch->unit_cost_snapshot = null;

        return [1 => $jeans, 2 => $brooch];
    }

    /**
     * @param array<int, ReceiptLine> $lines
     *
     * @return array<int, array{qty: int, damaged: int, unitCost: ?string}>
     */
    private function current(array $lines): array
    {
        $figures = [];
        foreach ($lines as $id => $line) {
            $figures[$id] = ['qty' => $line->qty, 'damaged' => $line->damaged_qty, 'unitCost' => $line->unit_cost_snapshot];
        }

        return $figures;
    }

    /** @return array<int, SubjectCost> */
    private function costs(?string $jeans = '1.9000', ?string $brooch = '3.0000'): array
    {
        $costs = [];
        foreach ([self::JEANS => $jeans, self::BROOCH => $brooch] as $subjectId => $average) {
            $cost = new SubjectCost();
            $cost->subject_id = $subjectId;
            $cost->weighted_avg_cost = $average;
            $costs[$subjectId] = $cost;
        }

        return $costs;
    }

    /**
     * @param list<ReceiptLineChange> $changes
     * @param array<int, int>         $onHand
     */
    private function plan(array $changes, array $onHand = [self::JEANS => 100, self::BROOCH => 20], ?string $fxRate = null): PlannedReceiptCorrection
    {
        $lines = $this->lines();

        return CorrectReceipt::plan($this->receipt($fxRate), $lines, $this->current($lines), $changes, $onHand, $this->costs());
    }

    public function testRemovingUnitsUnblendsThemAndKeepsOnlyTheChangedLine(): void
    {
        $plan = $this->plan([
            new ReceiptLineChange(1, 5, 0, '10.0000'),
            new ReceiptLineChange(2, 5, 0, null), // unchanged
        ]);

        self::assertCount(1, $plan->lines);
        self::assertSame(-5, $plan->lines[0]->qtyDelta());
        self::assertSame(40, $plan->lines[0]->poId);

        $jeans = $plan->products[self::JEANS];
        self::assertSame(-5, $jeans->delta);
        self::assertSame(100, $jeans->onHandBefore);
        self::assertSame('1.9000', $jeans->costBefore);
        // (100·1.90 − 5·10) / 95
        self::assertSame('1.4737', $jeans->after->cost);
        self::assertFalse($plan->reverses);
    }

    public function testRemovingMoreThanIsOnHandIsRefusedWithTheCounts(): void
    {
        try {
            $this->plan([new ReceiptLineChange(1, 2, 0, '10.0000')], [self::JEANS => 3, self::BROOCH => 20]);
            self::fail('Expected a refusal.');
        } catch (ReceiptCorrectionException $e) {
            self::assertSame(ReceiptCorrectionException::NOT_ON_HAND, $e->reasonCode);
            self::assertSame(self::JEANS, $e->subjectId);
            self::assertSame(8, $e->requested);
            self::assertSame(3, $e->available);
        }
    }

    public function testShiftingGoodToDamagedRemovesTheGoodUnits(): void
    {
        $plan = $this->plan([new ReceiptLineChange(1, 8, 2, '10.0000')]);

        self::assertSame(2, $plan->lines[0]->damagedDelta());
        self::assertSame(-2, $plan->products[self::JEANS]->delta);
    }

    public function testADamagedMiscountMovesNothing(): void
    {
        $plan = $this->plan([new ReceiptLineChange(1, 10, 3, '10.0000')]);

        self::assertCount(1, $plan->lines);
        self::assertSame([], $plan->products);
    }

    public function testACostCorrectionRevaluesWithoutMovingStock(): void
    {
        $plan = $this->plan([new ReceiptLineChange(1, 10, 0, '4')]);

        self::assertSame('4.0000', $plan->lines[0]->unitCostAfter);
        $jeans = $plan->products[self::JEANS];
        self::assertSame(0, $jeans->delta);
        // (100·1.90 + 10·(4 − 10)) / 100
        self::assertSame('1.3000', $jeans->after->cost);
    }

    public function testTheReceiptsRateConvertsLineCostsToBase(): void
    {
        // Supplier price 10.00 at 0.5 base per unit = 5.00 in base. 100 at 1.90, take 5 out at 5.00:
        // (190 − 25) / 95.
        $plan = $this->plan([new ReceiptLineChange(1, 5, 0, '10.0000')], fxRate: '0.50000000');

        self::assertSame('1.7368', $plan->products[self::JEANS]->after->cost);
    }

    public function testAnUncostedLineLeavesAtTheAverage(): void
    {
        $plan = $this->plan([new ReceiptLineChange(2, 2, 0, null)]);

        $brooch = $plan->products[self::BROOCH];
        self::assertSame(-3, $brooch->delta);
        self::assertSame('3.0000', $brooch->after->cost);
    }

    public function testEverythingToZeroReversesTheReceipt(): void
    {
        $plan = $this->plan([
            new ReceiptLineChange(1, 0, 0, '10.0000'),
            new ReceiptLineChange(2, 0, 0, null),
        ]);

        self::assertTrue($plan->reverses);
        self::assertCount(2, $plan->lines);
    }

    public function testAChangeThatChangesNothingIsRefused(): void
    {
        $this->expectException(ReceiptCorrectionException::class);
        $this->plan([new ReceiptLineChange(1, 10, 0, '10.0000')]);
    }

    public function testALineOfAnotherReceiptIsRefused(): void
    {
        $this->expectExceptionMessage('Line 99 is not a line of goods receipt 128.');
        $this->plan([new ReceiptLineChange(99, 1, 0, null)]);
    }

    public function testNegativeFiguresAreRefused(): void
    {
        $this->expectExceptionMessage('cannot be negative');
        $this->plan([new ReceiptLineChange(1, -1, 0, null)]);
    }
}
