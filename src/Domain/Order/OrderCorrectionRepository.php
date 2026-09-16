<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Domain\Order;

/**
 * Persistence boundary for {@see OrderCorrection} aggregates.
 *
 * @api
 */
interface OrderCorrectionRepository
{
    /**
     * Find one correction by surrogate id (16-byte binary UUIDv7), or null if not found.
     *
     * @param string $id 16-byte binary UUIDv7
     */
    public function findById(string $id): ?OrderCorrection;

    /**
     * All corrections against one order, ordered by `createdAt` ascending then id.
     *
     * @param string $orderId 16-byte binary UUIDv7
     *
     * @return list<OrderCorrection>
     */
    public function forOrder(string $orderId): array;

    /**
     * All unprocessed corrections (engine queue). Bounded by `$limit`.
     *
     * Used by the correction-processing engine to find work. Implementations should
     * order by `createdAt` ascending for FIFO processing.
     *
     * @return list<OrderCorrection>
     */
    public function unprocessed(int $limit = 100): array;

    /**
     * Insert or update one correction.
     *
     * If `$correction->id` is null, inserts and returns a new {@see OrderCorrection}
     * carrying the assigned id. If set, updates the existing row.
     */
    public function save(OrderCorrection $correction): OrderCorrection;

    /**
     * Delete one correction. Implementations may refuse to delete corrections with
     * `processedAt` set — that policy lives at the application layer.
     *
     * @param string $id 16-byte binary UUIDv7
     */
    public function delete(string $id): void;
}
