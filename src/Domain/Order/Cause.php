<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Domain\Order;

/**
 * Categorical origin of an order-correction reason — who or what caused the event.
 *
 * Used for reporting and as the primary input to merchant-liability classification.
 * Liability itself is not stored: it derives from `(cause, correction.type, direction,
 * store policy)` at the application layer. See
 * arch-order-events for the rationale.
 *
 * The vocabulary is intentionally coarse. Finer attribution (specific carrier, specific
 * supplier, specific gateway, etc.) lives in the reason `code` or the correction's
 * `payload`, not here.
 *
 * @api
 */
enum Cause: string
{
    /** Customer choice or customer-side responsibility (changed mind, wrong size, refused at door, late payment, …). */
    case Customer = 'customer';

    /** Merchant-side fault or process failure (defective from origin, wrong item picked, undisclosed reservation window, …). */
    case Merchant = 'merchant';

    /** Carrier or shipping process (lost in transit, damaged in transit, undeliverable, …). Merchant-liability for logistics events depends on direction and store policy. */
    case Logistics = 'logistics';
}
