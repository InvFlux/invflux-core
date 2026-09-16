<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Domain\Order;

/**
 * Persistence boundary for {@see OrderShipping} — an order's shipping arrangements.
 *
 * Concrete implementations live in storage adapters. Arrangements are always scoped to their order;
 * a projection reconciles them as a set per order, so the reads are by order and the writes are
 * bulk, never one arrangement at a time.
 *
 * @api
 */
interface OrderShippingRepository
{
    /**
     * Every arrangement on one order, in insertion order.
     *
     * @param string $orderId 16-byte binary UUIDv7
     *
     * @return list<OrderShipping>
     */
    public function forOrder(string $orderId): array;

    /**
     * Every arrangement on the given orders, in one read — for a pass that reconciles many orders
     * at once, which would otherwise read once per order.
     *
     * @param list<string> $orderIds 16-byte binary UUIDv7s; an empty list reads nothing
     *
     * @return list<OrderShipping>
     */
    public function forOrders(array $orderIds): array;

    /**
     * Insert or update many arrangements in a single bulk operation — never a per-row save loop.
     *
     * @param list<OrderShipping> $arrangements
     *
     * @return list<OrderShipping> the same records, with ids populated
     */
    public function saveAll(array $arrangements): array;

    /**
     * Delete the given arrangements in a single bulk operation.
     *
     * For projection reconciliation: an arrangement whose source item has left the source order is
     * removed, so the projection states what the order ships by now. Unlike an order line, it
     * carries no dispatch history of its own to preserve.
     *
     * @param list<OrderShipping> $arrangements records with populated ids; an empty list is a no-op
     */
    public function deleteAll(array $arrangements): void;
}
