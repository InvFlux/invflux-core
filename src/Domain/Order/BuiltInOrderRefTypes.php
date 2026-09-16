<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Domain\Order;

use Nandan108\InvFlux\Registry\RefTypeDefinition;

/**
 * Ref-type codes contributed by the order domain.
 *
 * Storage adapters seed `invflux_ref_types` from {@see all()} during install
 * (typically right after `MysqlOrderStore::install()`). These codes are used in
 * audit-event `ref_type_id` columns to point a row at a specific dispatch entity
 * — e.g., a `correction.created` event has `ref_type=correction, ref_id=$correction->id`.
 *
 * Note: `order` itself is already in the storage layer's baseline seed (since
 * the ledger has referenced orders since day one). Only the new dispatch entities
 * register here.
 *
 * @api
 */
final class BuiltInOrderRefTypes
{
    public const OWNER_KEY = 'order';

    /**
     * Build the canonical list.
     *
     * @return list<RefTypeDefinition>
     */
    public static function all(): array
    {
        return [
            new RefTypeDefinition('order_line', 'Order Line'),
            new RefTypeDefinition('correction', 'Order Correction'),
            new RefTypeDefinition('order_event', 'Order Event'),
        ];
    }
}
