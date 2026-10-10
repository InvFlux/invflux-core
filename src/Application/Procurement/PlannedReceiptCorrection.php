<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Application\Procurement;

/**
 * A receipt correction worked out against current stock and costs, before anything is written:
 * what {@see CorrectReceipt::preview()} answers, and what {@see CorrectReceipt} then records.
 *
 * @api
 */
final class PlannedReceiptCorrection
{
    /**
     * @param list<PlannedLineChange>             $lines    only the lines that change
     * @param array<int, ProductCorrectionEffect> $products per product with units to move or a cost to change
     * @param bool                                $reverses whether every line of the receipt ends at no units, good or damaged
     */
    public function __construct(
        public readonly int $receiptId,
        public readonly array $lines,
        public readonly array $products,
        public readonly bool $reverses,
    ) {
    }
}
