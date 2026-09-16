<?php

declare(strict_types=1);

namespace Tests\Application\Procurement;

use Nandan108\InvFlux\Application\Procurement\ReceiveGoods;
use PHPUnit\Framework\TestCase;

/**
 * The pure weighted-average-cost formula extracted from {@see ReceiveGoods}. Covers the three
 * branches: receipt-establishes-WAC (no prior cost / no prior stock), WAC blending, and the
 * seed-cost seed blending into the first receipt.
 */
final class WeightedAverageCostTest extends TestCase
{
    public function testReceiptEstablishesWacWhenNoPriorCost(): void
    {
        // No prior cost at all → the receipt's own unit cost (100 / 10) sets the WAC.
        self::assertSame('10.0000', ReceiveGoods::weightedAverageCost(0, null, 10, 100.0));
    }

    public function testReceiptEstablishesWacWhenNoPriorStock(): void
    {
        // A prior cost exists but there's no on-hand to weight against → receipt cost wins (600 / 50).
        self::assertSame('12.0000', ReceiveGoods::weightedAverageCost(0, '10.0000', 50, 600.0));
    }

    public function testBlendsAgainstPriorWac(): void
    {
        // (2·5 + 100) / (2 + 10) = 110 / 12 = 9.1667.
        self::assertSame('9.1667', ReceiveGoods::weightedAverageCost(2, '5.0000', 10, 100.0));
    }

    public function testBlendsAgainstSeedCost(): void
    {
        // The seed case: 100 on-hand valued at the seeded 10.00, receive 50 worth 600 (12 each) →
        // (100·10 + 600) / (100 + 50) = 1600 / 150 = 10.6667. priorCost is the seed_cost here.
        self::assertSame('10.6667', ReceiveGoods::weightedAverageCost(100, '10.0000', 50, 600.0));
    }
}
