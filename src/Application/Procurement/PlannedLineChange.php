<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Application\Procurement;

/**
 * One receipt line's figures before → after a planned correction. Unit costs are in the receipt's
 * line currency.
 *
 * @api
 */
final class PlannedLineChange
{
    public function __construct(
        public readonly int $receiptLineId,
        public readonly int $subjectId,
        public readonly ?int $poId,
        public readonly int $qtyBefore,
        public readonly int $qtyAfter,
        public readonly int $damagedBefore,
        public readonly int $damagedAfter,
        public readonly ?string $unitCostBefore,
        public readonly ?string $unitCostAfter,
    ) {
    }

    public function qtyDelta(): int
    {
        return $this->qtyAfter - $this->qtyBefore;
    }

    public function damagedDelta(): int
    {
        return $this->damagedAfter - $this->damagedBefore;
    }
}
