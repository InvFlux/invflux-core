<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Domain\Procurement;

/**
 * Where a goods receipt's unit cost comes from — the answer every intake owes to "what did this cost?".
 *
 * A receipt against a purchase order never reaches this enum: its cost comes from the order. It exists
 * for the source-less intake, where {@see ReceiptReason} selects the case, and it is the reason a
 * standalone receipt is a *costed* movement rather than a quantity adjustment. An on-hand correction
 * has no cost story at all, which is precisely what separates the two.
 *
 * @api
 */
enum CostSource: string
{
    /** The operator states it. Someone paid something, or made something, and only they know what. */
    case Entered = 'entered';

    /**
     * Computed from cost that already exists — an origin location's layers on a transfer, or the
     * components an assembly consumed. Asking for it would invite a number less true than the one we
     * can work out.
     */
    case Derived = 'derived';

    /**
     * The product's standing per-unit valuation stands in. Unlike a received layer this carries no
     * quantity, date or provenance of its own, so it is the weakest honest answer — used where the
     * true figure is unknowable rather than merely unentered.
     */
    case SeedFallback = 'seed_fallback';

    /** Nothing was paid. Zero is the fact, not a missing value. */
    case Free = 'free';
}
