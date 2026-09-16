<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Schema;

/**
 * Why a slot default is being asked for.
 *
 * The same (layer, dimension) pair can have different defaults depending on the operation:
 * with a bin-level hierarchy installed, a goods receipt lands on a receiving dock while a
 * stock correction lands on the general holding leaf — same layer, same dimension, same
 * install, different answer. The purpose is the input that distinguishes them.
 *
 * This enum is the **canonical set, not a closed one** — the same contract as the `stt`
 * dimension ({@see Stt}). An add-on may pass its own purpose string to
 * {@see SlotDefaultResolver::resolve()}; a resolver that recognises it answers, and one
 * that does not **throws** rather than guessing. Silently falling back would put stock in
 * the wrong place, which is materially worse than a failed operation.
 *
 * @api
 */
enum SlotPurpose: string
{
    /** Stock arriving from a supplier (goods receipt / putaway entry point). */
    case GoodsReceipt = 'goods_receipt';

    /** Manual on-hand correction, stock take, or reconciliation write-in/write-off. */
    case Correction = 'correction';

    /** Stock leaving toward a customer. */
    case Dispatch = 'dispatch';

    /** Stock coming back from a customer. */
    case Return_ = 'return';

    /** No operation-specific preference — use the dimension's declared default. */
    case Default_ = 'default';
}
