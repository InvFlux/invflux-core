<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Domain\Order;

/**
 * How a refund is to be / was dispatched. The vocabulary on the `mode` field of a
 * `refund.scheduled` event and the `refund_mode` field of `correction.refund_confirmed`.
 *
 * @api
 */
enum RefundMode: string
{
    /** No refund — correction is informational or non-monetary. */
    case None = 'none';

    /**
     * Refund owed but not auto-issued — the gateway can't refund automatically, so an
     * operator must settle it out-of-band. A `refund.scheduled(mode=manual)` event sits
     * unsettled (a "pending manual refund") until the operator's settle action issues
     * the WC refund record and emits {@see self::ManualConfirmed}.
     */
    case Manual = 'manual';

    /**
     * The refund was issued with operator attestation that the money movement is / will
     * be handled out-of-band (`wc_create_refund(refund_payment=false)` records it in WC).
     * The recorded mode on the terminal `correction.refund_confirmed` event.
     */
    case ManualConfirmed = 'manual_confirmed';

    /** Gateway-capable refund — issued automatically (sync at schedule + cron retry). */
    case Auto = 'auto';
}
