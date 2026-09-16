<?php

declare(strict_types=1);

namespace Tests\Domain\Procurement;

use Nandan108\InvFlux\Domain\Procurement\PurchaseTaxSummary;
use PHPUnit\Framework\TestCase;

final class PurchaseTaxSummaryTest extends TestCase
{
    public function testNoLinesYieldsNoTotals(): void
    {
        $summary = PurchaseTaxSummary::fromLines([]);

        self::assertSame([], $summary->rates);
        self::assertNull($summary->net, 'no costed line means no total — not a zero, which reads as free');
        self::assertNull($summary->tax);
        self::assertNull($summary->gross);
    }

    public function testSingleRateSumsNetAndAppliesTheRate(): void
    {
        $summary = PurchaseTaxSummary::fromLines([
            ['net' => 100.0, 'rate' => 20.0],
            ['net' => 50.0, 'rate' => 20.0],
        ]);

        self::assertCount(1, $summary->rates);
        self::assertEqualsWithDelta(20.0, $summary->rates[0]->rate, 0.001);
        self::assertEqualsWithDelta(150.0, $summary->rates[0]->net, 0.001);
        self::assertEqualsWithDelta(30.0, $summary->rates[0]->tax, 0.001);
        self::assertEqualsWithDelta(150.0, $summary->net, 0.001);
        self::assertEqualsWithDelta(30.0, $summary->tax, 0.001);
        self::assertEqualsWithDelta(180.0, $summary->gross, 0.001);
    }

    /**
     * The reason the summary exists: a mixed-rate basket is ordinary, and a single blended tax figure
     * cannot be checked against the invoice that follows.
     */
    public function testMixedRatesAreKeptApartAndOrderedAscending(): void
    {
        $summary = PurchaseTaxSummary::fromLines([
            ['net' => 100.0, 'rate' => 20.0],
            ['net' => 200.0, 'rate' => 5.5],
            ['net' => 100.0, 'rate' => 20.0],
        ]);

        self::assertCount(2, $summary->rates);
        self::assertEqualsWithDelta(5.5, $summary->rates[0]->rate, 0.001, 'ascending by rate');
        self::assertEqualsWithDelta(200.0, $summary->rates[0]->net, 0.001);
        self::assertEqualsWithDelta(11.0, $summary->rates[0]->tax, 0.001);
        self::assertEqualsWithDelta(20.0, $summary->rates[1]->rate, 0.001);
        self::assertEqualsWithDelta(200.0, $summary->rates[1]->net, 0.001);
        self::assertEqualsWithDelta(40.0, $summary->rates[1]->tax, 0.001);

        self::assertEqualsWithDelta(400.0, $summary->net, 0.001);
        self::assertEqualsWithDelta(51.0, $summary->tax, 0.001);
        self::assertEqualsWithDelta(451.0, $summary->gross, 0.001);
    }

    /** `20` and `20.0` are one rate, not two rows that happen to print alike. */
    public function testEquivalentRatesGroupTogether(): void
    {
        $summary = PurchaseTaxSummary::fromLines([
            ['net' => 10.0, 'rate' => 20.0],
            ['net' => 10.0, 'rate' => 20.00],
        ]);

        self::assertCount(1, $summary->rates);
        self::assertEqualsWithDelta(20.0, $summary->rates[0]->net, 0.001);
    }

    /**
     * A zero-rated regime states that no tax applies, which is not the same as a table of 0.00 rows —
     * so it gets a net-only summary and gross equal to net.
     */
    public function testAZeroRatedRegimeReportsNetOnly(): void
    {
        $summary = PurchaseTaxSummary::fromLines(
            [['net' => 100.0, 'rate' => 20.0]],
            chargesTax: false,
        );

        self::assertFalse($summary->chargesTax);
        self::assertSame([], $summary->rates, 'no rate table when the regime charges nothing');
        self::assertEqualsWithDelta(100.0, $summary->net, 0.001);
        self::assertEqualsWithDelta(0.0, $summary->tax, 0.001);
        self::assertEqualsWithDelta(100.0, $summary->gross, 0.001);
    }

    /** Amounts stay unrounded so no rounding policy is baked in where two renderers must agree. */
    public function testAmountsAreLeftUnrounded(): void
    {
        $summary = PurchaseTaxSummary::fromLines([['net' => 10.0, 'rate' => 8.1]]);

        self::assertEqualsWithDelta(0.81, $summary->tax, 0.00001);
        self::assertEqualsWithDelta(10.81, $summary->gross, 0.00001);
    }
}
