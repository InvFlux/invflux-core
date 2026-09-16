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
}
