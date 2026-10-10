<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Application\Costing;

/**
 * What correcting a receipt does to one product's average — see {@see WeightedAverage::correct()}.
 *
 * @api
 */
final class CorrectedAverage
{
    public function __construct(
        /** Total on hand once the correction has moved its units; may be negative from a short position. */
        public readonly int $onHand,
        /** The average after, as stored (4 decimals); null when the product has none. */
        public readonly ?string $cost,
        /**
         * Value, in base, the stock could not absorb: positive when it already left with shipped units,
         * either sign when no stock remains to carry it. Zero when the stock absorbed everything.
         */
        public readonly float $variance,
        /** Whether the stock absorbed the correction; false means the average was kept and the variance recorded. */
        public readonly bool $absorbed,
    ) {
    }
}
