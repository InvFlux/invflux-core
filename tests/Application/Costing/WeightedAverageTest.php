<?php

declare(strict_types=1);

namespace Tests\Application\Costing;

use Nandan108\InvFlux\Application\Costing\WeightedAverage;
use PHPUnit\Framework\TestCase;

/**
 * The moving-average arithmetic: a receipt blending in, and a correction to a receipt revaluing or
 * taking back what it brought — including the case where the stock left cannot absorb it.
 */
final class WeightedAverageTest extends TestCase
{
    public function testReceiptEstablishesTheAverageWhenThereIsNoPriorCost(): void
    {
        // No prior cost at all → the receipt's own unit cost (100 / 10) sets the average.
        self::assertSame('10.0000', WeightedAverage::blend(0, null, 10, 100.0));
    }

    public function testReceiptEstablishesTheAverageWhenThereIsNoPriorStock(): void
    {
        // A prior cost exists but there's no on-hand to weight against → receipt cost wins (600 / 50).
        self::assertSame('12.0000', WeightedAverage::blend(0, '10.0000', 50, 600.0));
        // A short position is no stock to weight either.
        self::assertSame('12.0000', WeightedAverage::blend(-3, '10.0000', 50, 600.0));
    }

    public function testBlendsAgainstThePriorAverage(): void
    {
        // (2·5 + 100) / (2 + 10) = 110 / 12 = 9.1667.
        self::assertSame('9.1667', WeightedAverage::blend(2, '5.0000', 10, 100.0));
    }

    public function testBlendsAgainstTheSeedCost(): void
    {
        // 100 on hand valued at the seeded 10.00, receive 50 worth 600 (12 each) →
        // (100·10 + 600) / (100 + 50) = 1600 / 150 = 10.6667. The prior cost is the seed here.
        self::assertSame('10.6667', WeightedAverage::blend(100, '10.0000', 50, 600.0));
    }

    public function testRemovingUnitsBeforeAnyShippedReproducesTheCorrectReceipt(): void
    {
        // 90 at 1.00, a receipt of 10 at 10.00 → 1.90 over 100. Only 5 arrived. Nothing has left,
        // so the correction must leave what a receipt of 5 would have: (90 + 50) / 95 = 1.4737.
        $after = WeightedAverage::correct(100, '1.9000', 10, 10.0, 5, 10.0);

        self::assertSame(95, $after->onHand);
        self::assertSame('1.4737', $after->cost);
        self::assertSame(0.0, $after->variance);
        self::assertTrue($after->absorbed);
        self::assertSame(WeightedAverage::blend(90, '1.0000', 5, 50.0), $after->cost);
    }

    public function testCorrectingTheCostBeforeAnyShippedReproducesTheCorrectReceipt(): void
    {
        // 90 at 1.00, a receipt of 10 recorded at 10.00 but invoiced at 4.00.
        // A correct receipt would have left (90 + 40) / 100 = 1.30.
        $after = WeightedAverage::correct(100, '1.9000', 10, 10.0, 10, 4.0);

        self::assertSame(100, $after->onHand);
        self::assertSame('1.3000', $after->cost);
        self::assertTrue($after->absorbed);
    }

    public function testACostCorrectionRevaluesOnlyTheReceiptUnitsStillHere(): void
    {
        // The receipt brought 10 at 10.00; only 4 units of the product remain. Repricing to 4.00
        // revalues those 4 alone: (4·1.90 + 4·(4 − 10)) / 4 → negative, so the stock cannot take it.
        $after = WeightedAverage::correct(4, '1.9000', 10, 10.0, 10, 4.0);

        self::assertFalse($after->absorbed);
        self::assertSame('1.9000', $after->cost);
        // What did not fit: 4·1.90 − (7.60 − 24.00) = 24.00 — all of the repricing, since the
        // average stayed.
        self::assertSame(24.0, $after->variance);

        // With 40 on hand all 10 are still here and the repricing fits: (40·1.90 − 10·6) / 40.
        $fits = WeightedAverage::correct(40, '1.9000', 10, 10.0, 10, 4.0);
        self::assertTrue($fits->absorbed);
        self::assertSame('0.4000', $fits->cost);
    }

    public function testRemovingMoreValueThanTheStockHoldsKeepsTheAverageAndRecordsTheVariance(): void
    {
        // The plan's worked case: 90 at 1.00, receipt of 10 at 10.00 → 1.90. 92 ship, 8 remain.
        // Correcting 10 → 5 would un-blend to (15.20 − 50) / 3 < 0. The average stays at 1.90 and
        // the variance is 5 × (10 − 1.90) = 40.50, the value that already left with shipped units.
        $after = WeightedAverage::correct(8, '1.9000', 10, 10.0, 5, 10.0);

        self::assertSame(3, $after->onHand);
        self::assertSame('1.9000', $after->cost);
        self::assertSame(40.5, $after->variance);
        self::assertFalse($after->absorbed);
    }

    public function testRemovingEverythingLeftRecordsWhateverValueWouldSitOnNoStock(): void
    {
        // 5 on hand at 1.90, all of them the receipt's, and the receipt is reversed. 9.50 of value
        // leaves at 10.00 a unit, 50.00: 40.50 more than the stock held.
        $over = WeightedAverage::correct(5, '1.9000', 10, 10.0, 5, 10.0);
        self::assertSame(0, $over->onHand);
        self::assertSame('1.9000', $over->cost);
        self::assertSame(40.5, $over->variance);

        // Received cheaper than the average: removing all 5 at 1.00 leaves 4.50 on no stock, which
        // is a negative variance — value the shipped units should have carried and did not.
        $under = WeightedAverage::correct(5, '1.9000', 10, 1.0, 5, 1.0);
        self::assertSame(0, $under->onHand);
        self::assertSame(-4.5, $under->variance);
    }

    public function testAddingUnitsBlendsThemInAtTheCorrectedCost(): void
    {
        // 100 at 1.90 on hand; the receipt said 10 at 10.00 but 12 came: (190 + 20) / 102.
        $after = WeightedAverage::correct(100, '1.9000', 10, 10.0, 12, 10.0);

        self::assertSame(102, $after->onHand);
        self::assertSame('2.0588', $after->cost);
    }

    public function testAProductWithNoAverageOnlyMovesQuantity(): void
    {
        $after = WeightedAverage::correct(10, null, 10, 0.0, 6, 0.0);

        self::assertSame(6, $after->onHand);
        self::assertNull($after->cost);
        self::assertSame(0.0, $after->variance);
    }
}
