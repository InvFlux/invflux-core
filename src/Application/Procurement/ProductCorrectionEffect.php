<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Application\Procurement;

use Nandan108\InvFlux\Application\Costing\CorrectedAverage;

/**
 * What a planned receipt correction does to one product: the units it moves and its average cost
 * before → after, in the store base.
 *
 * @api
 */
final class ProductCorrectionEffect
{
    public function __construct(
        public readonly int $subjectId,
        /** Total on hand before the correction moves anything; may be negative from a short position. */
        public readonly int $onHandBefore,
        /** Units the correction moves: negative takes them out, positive brings them in. */
        public readonly int $delta,
        /** The average before; null when the product has none. */
        public readonly ?string $costBefore,
        public readonly CorrectedAverage $after,
    ) {
    }
}
