<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Results;

/**
 * Represent the outcome of attempting to book stock for an order.
 *
 * @api
 */
enum BookingOutcome: string
{
    case BOOKED_RESERVED = 'booked_reserved';
    case BOOKED_RECOVERED = 'booked_recovered';
    /**
     * Late-payment recovery captured stock for at least one order line but not all of them.
     *
     * The per-line capture loop in `EssentialsFlowRunner::recoverCancelledOrder`
     * attempts to book each line independently;
     * when some lines succeed and others fail (insufficient `atp`), the order-level outcome
     * is `BOOKED_RECOVERED_PARTIAL`. LPSC resolution paths (Path 2 / Path 3 / Path 1) then
     * branch on the configured `partial_capture_resolution` setting to decide whether to
     * cancel + restore cart, offer customer choice, or hold for merchant review.
     */
    case BOOKED_RECOVERED_PARTIAL = 'booked_recovered_partial';
    case RECOVERY_FAILED = 'recovery_failed';
    case FAILED = 'failed';
}
