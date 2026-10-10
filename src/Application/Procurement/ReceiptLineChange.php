<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Application\Procurement;

/**
 * What one receipt line should say once corrected: its good and damaged quantities and its unit
 * cost, as figures after rather than as deltas — the correction works out the difference from the
 * line's current figures, so a caller never has to know what earlier corrections did.
 *
 * @api
 */
final class ReceiptLineChange
{
    public function __construct(
        public readonly int $receiptLineId,
        public readonly int $qty,
        public readonly int $damaged,
        /** In the receipt's line currency; null leaves the line uncosted. */
        public readonly ?string $unitCost,
    ) {
    }
}
