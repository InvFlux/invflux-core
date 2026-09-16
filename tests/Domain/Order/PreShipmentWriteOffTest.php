<?php

declare(strict_types=1);

namespace Tests\Domain\Order;

use Nandan108\InvFlux\Domain\Order\PreShipmentStock;
use Nandan108\InvFlux\Domain\Order\PreShipmentWriteOff;
use PHPUnit\Framework\TestCase;

final class PreShipmentWriteOffTest extends TestCase
{
    private static function stock(int $atp = 0, int $res = 0, int $ctd = 2, int $stagedElsewhere = 0, int $lineClaim = 2, int $demand = 2): PreShipmentStock
    {
        return new PreShipmentStock($atp, $res, $ctd, $demand, $stagedElsewhere, $lineClaim);
    }

    public function testTheShortfallIsWhatPaidOrdersAreOwedBeyondCommittedStock(): void
    {
        self::assertSame(2, self::stock(ctd: 3, demand: 5)->shortfall());
        self::assertSame(0, self::stock(ctd: 5, demand: 3)->shortfall());
    }

    /** A unit for sale or reserved would cover a paid order first, so the product is not short. */
    public function testNothingIsShortWhileAnythingIsForSaleOrReserved(): void
    {
        self::assertSame(0, self::stock(atp: 8, ctd: 3, demand: 4)->shortfall());
        self::assertSame(0, self::stock(res: 1, ctd: 3, demand: 4)->shortfall());
    }

    public function testADefectiveUnitIsWrittenOffOnlyWhenNothingCanReplaceIt(): void
    {
        self::assertTrue(PreShipmentWriteOff::allows('defective', self::stock()));
        self::assertFalse(PreShipmentWriteOff::allows('defective', self::stock(atp: 1)), 'a unit for sale replaces it');
        self::assertFalse(PreShipmentWriteOff::allows('defective', self::stock(res: 1)), 'an unpaid order ranks below this one');
    }

    /** Other paid orders' units are not a substitute: cancelling this line instead is the merchant's choice. */
    public function testADefectiveUnitMayBeWrittenOffWhileOtherPaidOrdersHoldUnits(): void
    {
        self::assertTrue(PreShipmentWriteOff::allows('defective', self::stock(ctd: 5, lineClaim: 2)));
    }

    public function testMissingAtPickNeedsTheShelfToHoldNothingForOthers(): void
    {
        self::assertTrue(PreShipmentWriteOff::allows('short_pick', self::stock(ctd: 5, stagedElsewhere: 3, lineClaim: 2)));
        self::assertFalse(
            PreShipmentWriteOff::allows('short_pick', self::stock(ctd: 5, stagedElsewhere: 1, lineClaim: 2)),
            'two units should still be on the shelf for other orders',
        );
        self::assertFalse(PreShipmentWriteOff::allows('short_pick', self::stock(atp: 1)));
        self::assertSame(2, self::stock(ctd: 5, stagedElsewhere: 1, lineClaim: 2)->onShelfForOthers());
    }

    public function testAReasonThatDoesNotWriteOffIsNeverRefused(): void
    {
        self::assertTrue(PreShipmentWriteOff::allows('pricing_error', self::stock(atp: 9)));
    }
}
