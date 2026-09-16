<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Domain\Procurement;

/**
 * Lifecycle status shared by {@see Supplier} and {@see SupplierContact}.
 *
 * A departed supplier or contact is set {@see self::Inactive} rather than deleted, so the history
 * of past PO sends stays intact while they drop out of the send/selection pickers.
 *
 * @api
 */
enum SupplierStatus: string
{
    case Active = 'active';

    case Inactive = 'inactive';
}
