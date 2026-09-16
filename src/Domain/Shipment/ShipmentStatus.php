<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Domain\Shipment;

/**
 * Lifecycle of an {@see Shipment} row.
 *
 * Essentials tier only ever writes {@see self::Processed} (the single happy-path
 * dispatch: one shipment, all outstanding lines, stamped at confirm time).
 * {@see self::Saved} (draft, pre-confirm), {@see self::Cancelled}, and
 * {@see self::Reversed} exist in the schema for the Pro per-shipment endpoint
 * (save-then-process, partial fulfillment, admin resend/reversal) and are not
 * produced by the Essentials path. String-backed so the value is self-describing in
 * the `status` ENUM column and stable across refactors.
 */
enum ShipmentStatus: string
{
    /** Draft shipment composed but not yet dispatched (Pro per-shipment flow). */
    case Saved = 'saved';

    /** Dispatched — stock has left `oh.ctd`, cost stamped. The Essentials terminal state. */
    case Processed = 'processed';

    /** Voided before processing (Pro). */
    case Cancelled = 'cancelled';

    /** Admin reversal of a processed shipment — short-pack recovery (Pro). */
    case Reversed = 'reversed';
}
