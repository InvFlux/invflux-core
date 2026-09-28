<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Domain\Order;

/**
 * Persistence boundary for {@see Order} aggregates.
 *
 * Concrete implementations live in storage adapters (e.g.,
 * `Nandan108\InvFlux\Storage\Mysql\Order\MysqlOrderRepository`). Application
 * code in adapters depends only on this interface.
 *
 * Lines are managed separately via {@see OrderLineRepository}.
 *
 * @api
 */
interface OrderRepository
{
    /**
     * Find one order by surrogate id (16-byte binary UUIDv7), or null if not found.
     *
     * @param string $id 16-byte binary UUIDv7
     */
    public function findById(string $id): ?Order;

    /**
     * Find one order by its external natural key, or null if not found.
     *
     * @param non-empty-string $sourceSystem
     * @param non-empty-string $externalId
     */
    public function findByExternalRef(string $sourceSystem, string $externalId): ?Order;

    /**
     * Insert or update one order.
     *
     * If `$order->id` is null, inserts and returns a new {@see Order} carrying the
     * assigned surrogate id. If `$order->id` is set, updates the existing row.
     * Implementations must enforce the `(sourceSystem, externalId)` UNIQUE constraint;
     * concurrent inserts of the same natural key must resolve to a single row.
     */
    public function save(Order $order): Order;

    /**
     * Reserve a run of `$count` consecutive line numbers on one order, and return the first.
     *
     * The caller gets `[first, first + count)` and nobody else will ever get any of them —
     * {@see Order::$next_line_no} only moves forward, so a number is not handed out twice even
     * after the line that held it is deleted.
     *
     * **Atomic against concurrent projection of the same order**, which is not hypothetical: the
     * WooCommerce projection fires on every order save, and two requests touching one order will
     * race here. A read-then-write would hand both the same numbers and the second insert would
     * collide with the first on the primary key. Implementations must make the reservation a
     * single statement, or hold the order row while they do it.
     *
     * Reserves the whole run in one call rather than one number at a time, for the reason every
     * read on {@see OrderLineRepository} is plural: a per-line allocator is a per-line round trip.
     *
     * @param string $orderId 16-byte binary UUIDv7
     * @param int    $count   how many numbers to reserve; must be positive
     *
     * @return int the first reserved number
     */
    public function allocateLineNumbers(string $orderId, int $count): int;
}
