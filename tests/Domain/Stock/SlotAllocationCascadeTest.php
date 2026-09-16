<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Tests\Domain\Stock;

use Nandan108\InvFlux\Domain\Stock\SlotAllocationCascade;
use PHPUnit\Framework\TestCase;

/**
 * Pure allocator maintaining the commercial-slot invariant
 * (`ctd` deficit ⇒ `atp = res = 0`).
 */
final class SlotAllocationCascadeTest extends TestCase
{
    public function testZeroDeltaMovesNothing(): void
    {
        self::assertSame(['atp' => 0, 'res' => 0, 'ctd' => 0], SlotAllocationCascade::allocate(0, 3, 2, 5));
    }

    public function testNegativeDrainsAtpFirst(): void
    {
        self::assertSame(['atp' => -2, 'res' => 0, 'ctd' => 0], SlotAllocationCascade::allocate(-2, 3, 2, 5));
    }

    public function testNegativeSpillsAtpThenResThenCtd(): void
    {
        // atp=3, res=2, ctd=5 (total 10); lose 8 → atp -3, res -2, ctd -3.
        self::assertSame(['atp' => -3, 'res' => -2, 'ctd' => -3], SlotAllocationCascade::allocate(-8, 3, 2, 5));
    }

    public function testNegativeClampsAtZeroTotal(): void
    {
        self::assertSame(['atp' => -3, 'res' => -2, 'ctd' => -5], SlotAllocationCascade::allocate(-15, 3, 2, 5));
    }

    public function testPositiveWithNoDeficitGoesToAtp(): void
    {
        self::assertSame(
            ['atp' => 4, 'res' => 0, 'ctd' => 0],
            SlotAllocationCascade::allocate(4, 3, 2, 5, soldQty: 5, reservedQty: 2),
        );
    }

    public function testPositiveFillsCtdDeficitFirst(): void
    {
        // sold 8 but only ctd=5 → deficit 3; +4 fills ctd to 8 (heals fulfillability), 1 to atp.
        self::assertSame(
            ['atp' => 1, 'res' => 0, 'ctd' => 3],
            SlotAllocationCascade::allocate(4, 3, 2, 5, soldQty: 8, reservedQty: 2),
        );
    }

    public function testPositiveFillsCtdThenResThenAtp(): void
    {
        self::assertSame(
            ['atp' => 2, 'res' => 1, 'ctd' => 2],
            SlotAllocationCascade::allocate(5, 3, 2, 5, soldQty: 7, reservedQty: 3),
        );
    }

    public function testPositiveNeverExceedsTheDeficitTargets(): void
    {
        self::assertSame(
            ['atp' => 0, 'res' => 0, 'ctd' => 1],
            SlotAllocationCascade::allocate(1, 3, 2, 5, soldQty: 6, reservedQty: 2),
        );
    }

    public function testAllocatedDeltasSumToAchievablePortion(): void
    {
        $out = SlotAllocationCascade::allocate(-8, 3, 2, 5);
        self::assertSame(-8, $out['atp'] + $out['res'] + $out['ctd']);

        $clamped = SlotAllocationCascade::allocate(-15, 3, 2, 5);
        self::assertSame(-10, $clamped['atp'] + $clamped['res'] + $clamped['ctd']);
    }

    // ── releasableFromCtd: the cancellation-restock cap ─────────────────────────────

    public function testReleaseFullWhenAtpPositive(): void
    {
        // atp=1 > 0 proves (by the invariant) there is no deficit, so the full 3 release even though
        // the demand count looks larger than ctd (a stale/unbooked order inflating the count).
        self::assertSame(3, SlotAllocationCascade::releasableFromCtd(qty: 3, atp: 1, res: 0, ctd: 3, demand: 99));
    }

    public function testReleaseFullWhenResPositive(): void
    {
        self::assertSame(3, SlotAllocationCascade::releasableFromCtd(qty: 3, atp: 0, res: 2, ctd: 3, demand: 99));
    }

    public function testReleaseFullyRestocksWhenNoDeficit(): void
    {
        // atp=res=0, ctd=5 backs demand 2 after this cancel of 3 → full 3 releasable (surplus 3 ≥ 3).
        self::assertSame(3, SlotAllocationCascade::releasableFromCtd(qty: 3, atp: 0, res: 0, ctd: 5, demand: 2));
    }

    public function testReleaseCapsAtSurplusInDeficit(): void
    {
        // atp=res=0 (a real deficit regime); ctd=5 but 4 still owed → only 1 surplus is releasable.
        self::assertSame(1, SlotAllocationCascade::releasableFromCtd(qty: 3, atp: 0, res: 0, ctd: 5, demand: 4));
    }

    public function testReleaseIsZeroWhenFullyOversold(): void
    {
        // atp=res=0, ctd=3 but 4 still owed after the cancel → deficit; nothing may become sellable.
        self::assertSame(0, SlotAllocationCascade::releasableFromCtd(qty: 3, atp: 0, res: 0, ctd: 3, demand: 4));
    }

    public function testReleaseNeverExceedsTheFreedQuantity(): void
    {
        // Large surplus, but only 3 units were cancelled → at most 3 move.
        self::assertSame(3, SlotAllocationCascade::releasableFromCtd(qty: 3, atp: 0, res: 0, ctd: 20, demand: 0));
    }
}
