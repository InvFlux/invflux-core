<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Contracts\Inventory;

use Nandan108\InvFlux\Mutation\MetaEvent;

/**
 * Record configuration and schema audit events.
 *
 * @api
 */
interface MetaLedger
{
    /** Append one meta event to the configuration ledger. */
    public function recordMetaEvent(MetaEvent $event): void;
}
