<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Domain\Order;

/**
 * When a correction may write units off before they ship, reason by reason.
 *
 * A pre-shipment write-off says two things at once: the units held for the order are unusable or
 * not there, **and** nothing can replace them, so the order gives those units up. The second half is
 * what each reason has to be coherent with:
 *
 * - **Defective from origin** — the unit in the packer's hands is bad, and no fit unit is to be had.
 *   A unit for sale, or one reserved for an unpaid order, would do, so the write-off needs neither to
 *   exist. When the only other units belong to other paid orders, writing this line off is legitimate:
 *   the merchant chooses to cancel it rather than make another order short.
 * - **Missing at pick** — the shelf is empty. Every unit the system expects there contradicts that:
 *   those for sale, those reserved, and those committed to other orders but not yet picked. If other
 *   orders' units should still be on the shelf and are not, the discrepancy is larger than this order —
 *   a stock count, not an order correction.
 *
 * Where a write-off is refused, the units are an on-hand correction: a fact about the shelf, which
 * then leaves the order to ship from what remains, or to be cancelled as out of stock. A cancellation
 * needs no such rule: it gives units back rather than destroying them.
 *
 * @api
 */
final class PreShipmentWriteOff
{
    public const DEFECTIVE = 'defective';
    public const MISSING_AT_PICK = 'short_pick';

    /**
     * May units be written off before shipment for this reason, given the product's stock? True for
     * a reason that does not write off ({@see BuiltInCorrectionReasons::writesOff()}).
     */
    public static function allows(string $reasonCode, PreShipmentStock $stock): bool
    {
        if (!BuiltInCorrectionReasons::writesOff($reasonCode)) {
            return true;
        }
        $noSubstitute = 0 === $stock->substitutes();

        return self::MISSING_AT_PICK === $reasonCode
            ? $noSubstitute && 0 === $stock->onShelfForOthers()
            : $noSubstitute;
    }
}
