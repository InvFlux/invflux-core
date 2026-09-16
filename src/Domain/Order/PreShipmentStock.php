<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Domain\Order;

/**
 * A product's stock as a pre-shipment write-off sees it: what could replace the units being written
 * off, and what the shelf should still hold for other orders. Read by {@see PreShipmentWriteOff}.
 *
 * @api
 */
final class PreShipmentStock
{
    public function __construct(
        /** Units for sale. */
        public readonly int $atp,
        /** Units reserved for orders not yet paid, which rank below a paid order. */
        public readonly int $res,
        /** Units committed to paid orders. */
        public readonly int $ctd,
        /** Units paid orders are owed and have not shipped — what `ctd` should cover. */
        public readonly int $demand,
        /** Committed units already staged — picked — on other orders' lines. */
        public readonly int $stagedElsewhere,
        /** Committed units this line still holds: its outstanding quantity, the units being written off included. */
        public readonly int $lineClaim,
    ) {
    }

    /**
     * Units that could replace the ones being written off without taking them from another paid
     * order: those for sale, then those reserved for an unpaid one — the order the stock is drawn
     * from anyway ({@see \Nandan108\InvFlux\Domain\Stock\SlotAllocationCascade}).
     */
    public function substitutes(): int
    {
        return max(0, $this->atp) + max(0, $this->res);
    }

    /**
     * Units the product is short: what paid orders are owed beyond what is committed — and none while
     * anything is for sale or reserved, which would cover them first (the invariant "a `ctd` deficit
     * means nothing is for sale or reserved", read the other way). This is what "out of stock" may
     * cancel: a cancellation beyond it would put existing units back on sale.
     */
    public function shortfall(): int
    {
        return $this->substitutes() > 0 ? 0 : max(0, $this->demand - $this->ctd);
    }

    /**
     * Committed units the shelf should still hold for other orders: what is committed, less what
     * other orders have already picked and what this line claims.
     */
    public function onShelfForOthers(): int
    {
        return max(0, $this->ctd - $this->stagedElsewhere - $this->lineClaim);
    }
}
