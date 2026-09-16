<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Domain\Order;

/**
 * Persistence boundary for {@see OrderCharge} — an order's order-level charges.
 *
 * Concrete implementations live in storage adapters. Charges are always scoped to their order; a
 * projection reconciles them as a set per order, so the reads are by order and the writes are bulk,
 * never one charge at a time.
 *
 * @api
 */
interface OrderChargeRepository
{
    /**
     * Every charge on one order, in insertion order.
     *
     * @param string $orderId 16-byte binary UUIDv7
     *
     * @return list<OrderCharge>
     */
    public function forOrder(string $orderId): array;

    /**
     * Every charge on the given orders, in one read — for a pass that reconciles many orders at
     * once, which would otherwise read once per order.
     *
     * @param list<string> $orderIds 16-byte binary UUIDv7s; an empty list reads nothing
     *
     * @return list<OrderCharge>
     */
    public function forOrders(array $orderIds): array;

    /**
     * Insert or update many charges in a single bulk operation — never a per-row save loop.
     *
     * @param list<OrderCharge> $charges
     *
     * @return list<OrderCharge> the same records, with ids populated
     */
    public function saveAll(array $charges): array;

    /**
     * Delete the given charges in a single bulk operation.
     *
     * For projection reconciliation: a charge whose source item has left the source order is
     * removed, so the projection states what the order charges by now. What was already refunded
     * against it is the source system's record, not this row's.
     *
     * @param list<OrderCharge> $charges records with populated ids; an empty list is a no-op
     */
    public function deleteAll(array $charges): void;
}
