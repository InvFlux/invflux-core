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
    /**
     * An order booking its **own** reservation found fewer units in it than it reserved.
     *
     * A tentative hold reserves *quantity*, and quantity can be drawn away from underneath it while
     * the hold stands: a write-off cascades `atp → res → ctd`, and a document raising what another
     * order owes commits out of `res` once free stock is gone. So the reservation record can be live
     * and its units already spent.
     *
     * This exists because the alternative is silent. The booking flow reports success having moved
     * nothing — a transaction committed with zero rows — and the order then reads as committed while
     * holding no unit at all, surfacing only at dispatch as an order with nothing to ship.
     *
     * Distinct from {@see BOOKED_RECOVERED_PARTIAL} because the customer's situation is different
     * and they are told so: a partial *recovery* follows a reservation that **expired**, whereas
     * this customer's reservation never lapsed. Telling them their reservation expired would be
     * false.
     */
    case BOOKED_RESERVED_PARTIAL = 'booked_reserved_partial';
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
