<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Domain\Shipment;

/**
 * Valuation method that produced a {@see ShipmentLine::$unit_cost}.
 *
 * Lives on the shipment line so Pro FIFO writes layer-consumed cost into the
 * same surface as Essentials WAC with no schema change — they share the recognition
 * moment (dispatch), differing only in method.
 */
enum CostBasis: string
{
    /** Weighted-average cost — the Essentials valuation method. */
    case Wac = 'wac';

    /** First-in-first-out layer cost — the Pro valuation method. */
    case Fifo = 'fifo';
}
