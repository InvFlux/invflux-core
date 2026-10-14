<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Domain\Procurement;

/**
 * Who answers for a part of a shortfall. A shortfall that is ours never counts against the supplier;
 * one in transit answers to whoever bore the risk then — the supplier under a D-term, the carrier
 * (answering to us) once risk had passed to us.
 *
 * @api
 */
enum ShortfallParty: string
{
    case Supplier = 'supplier';
    case Carrier = 'carrier';
    case Us = 'us';
    case Unknown = 'unknown';
}
