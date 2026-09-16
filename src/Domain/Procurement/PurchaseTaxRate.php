<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Domain\Procurement;

/**
 * One rate's share of a purchase order — the row a reader checks against the supplier's invoice.
 * See {@see PurchaseTaxSummary} for why the shares are kept apart rather than blended.
 *
 * @api
 */
final class PurchaseTaxRate
{
    public function __construct(
        /** Percentage, e.g. 8.1 or 20.0. */
        public readonly float $rate,
        /** Sum of the line nets carrying this rate. */
        public readonly float $net,
        /** Tax on that net, unrounded — the caller formats. */
        public readonly float $tax,
    ) {
    }
}
