<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Domain\Procurement;

/**
 * Why a part of a line did not arrive. Picked by the operator — the one thing a count cannot tell —
 * and only among the causes that fit the part ({@see ShortfallPart::causes()}): the same cause means
 * different things in different parts (`out_of_stock` before confirming is a warning, after it a
 * broken commitment), so the part carries that difference and no second code is needed.
 *
 * There is no `other`: free text goes in the note, which `unknown` requires.
 *
 * @api
 */
enum ShortfallCause: string
{
    case OutOfStock = 'out_of_stock';
    case Discontinued = 'discontinued';
    /** The supplier refused part of the order — a minimum, credit, a dispute. */
    case Declined = 'declined';
    /** We reduced the order before the supplier confirmed it. */
    case BuyerReduced = 'buyer_reduced';
    /** The supplier packed fewer than it confirmed, by mistake — a packing error. */
    case ShippedShort = 'shipped_short';
    /** We cancelled the remainder after confirmation. */
    case BuyerCancelled = 'buyer_cancelled';
    case LostInTransit = 'lost_in_transit';
    /** Miscounted, or a shipping notice that did not match the goods. */
    case CountedShort = 'counted_short';
    case DamagedInTransit = 'damaged_in_transit';
    /** Damaged before it left the supplier — packing. */
    case DamagedAtOrigin = 'damaged_at_origin';
    case RejectedOnInspection = 'rejected_on_inspection';
    case Unknown = 'unknown';
    // Last on purpose: a value appended to the column's ENUM converges as a safe change on an
    // existing table; one inserted mid-list would reorder the stored values.
    /**
     * The supplier confirmed, then deliberately did not ship, for a reason other than stock — a
     * credit hold, a dispute, a minimum not met; the reason goes in the note. Apart from
     * {@see self::ShippedShort}, which is unintended (a packing error).
     */
    case Withheld = 'withheld';
}
