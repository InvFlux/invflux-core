<?php

declare(strict_types=1);

namespace Tests\Domain\Order;

use Nandan108\InvFlux\Domain\Order\BuiltInCorrectionTypes;
use Nandan108\InvFlux\Domain\Order\OrderCorrectionType;
use PHPUnit\Framework\TestCase;

final class BuiltInCorrectionTypesTest extends TestCase
{
    public function testReturnsAllCanonicalCodes(): void
    {
        $codes = array_map(static fn (OrderCorrectionType $t): string => $t->code, BuiltInCorrectionTypes::all());

        // The canonical built-in codes; any addition is a deliberate domain decision
        // and should be reflected in this test.
        $expected = [
            'cancel_system', 'cancel_customer', 'cancel_merchant', 'cancel_reversal',
            'writeoff_defective', 'writeoff_missing',
            'shortfall_disclosed', 'shortfall_undisclosed', 'capture_short',
            'return_resaleable', 'return_resaleable_exception',
            'refund_external', 'refund_external_restock',
            'refund_external_pre', 'refund_external_pre_restock',
        ];
        sort($codes);
        sort($expected);

        $this->assertSame($expected, $codes);
    }

    public function testNoDuplicateCodes(): void
    {
        $codes = array_map(static fn (OrderCorrectionType $t): string => $t->code, BuiltInCorrectionTypes::all());

        $this->assertSame(count($codes), count(array_unique($codes)));
    }

    public function testAllSeedRecordsHaveNullId(): void
    {
        // Seed Records are pre-persistence; storage install() assigns ids on INSERT.
        foreach (BuiltInCorrectionTypes::all() as $type) {
            $this->assertNull($type->id, sprintf('Seed Record for %s should have null id', $type->code));
        }
    }

    public function testSlotMovementMatrixContract(): void
    {
        // The 2×2 (pre_dispatch × restock) drives slot movement through the generic engine:
        //   (true,  true)  → ctd → atp   (cancellations + pre-dispatch external refund restock)
        //   (true,  false) → ctd → nil   (pre-shipment write-off, shortfall, or pre-dispatch external refund no-restock)
        //   (false, true)  → nil → atp   (post-shipment return restock, post-dispatch external refund restock)
        //   (false, false) → no slot movement (post-dispatch external refund no-restock; cancellation reversal)
        // All refund_external* types now carry accurate flags and route through process().
        // `cancel_reversal` is (false, false) — no engine slot movement of its own; it restores
        // `qty_corrected` via the dedicated reversal path and its stock rides the normal booking path.
        $matrix = [];
        foreach (BuiltInCorrectionTypes::all() as $type) {
            $quadrant = ($type->pre_dispatch ? 'pre' : 'post').($type->restock ? '-restock' : '-noRestock');
            $matrix[$quadrant][] = $type->code;
        }

        $this->assertEqualsCanonicalizing(
            ['cancel_system', 'cancel_customer', 'cancel_merchant', 'refund_external_pre_restock'],
            $matrix['pre-restock'] ?? [],
        );
        $this->assertEqualsCanonicalizing(
            ['writeoff_defective', 'writeoff_missing', 'shortfall_disclosed', 'shortfall_undisclosed', 'capture_short', 'refund_external_pre'],
            $matrix['pre-noRestock'] ?? [],
        );
        $this->assertEqualsCanonicalizing(
            ['return_resaleable', 'return_resaleable_exception', 'refund_external_restock'],
            $matrix['post-restock'] ?? [],
        );
        $this->assertEqualsCanonicalizing(
            ['refund_external', 'cancel_reversal'],
            $matrix['post-noRestock'] ?? [],
        );
    }
}
