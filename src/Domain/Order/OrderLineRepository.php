<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Domain\Order;

use Nandan108\InvFlux\Domain\Subject\SubjectId;

/**
 * Persistence boundary for {@see OrderLine} entities.
 *
 * Concrete implementations live in storage adapters. Lines are always scoped to their
 * parent order; there is no global-line listing in this interface.
 *
 * @api
 */
interface OrderLineRepository
{
    /**
     * Find one line by surrogate id (16-byte binary UUIDv7), or null if not found.
     *
     * @param string $id 16-byte binary UUIDv7
     */
    public function findById(string $id): ?OrderLine;

    /**
     * Find one line by `(orderId, externalLineRef)` natural key.
     *
     * @param string           $orderId         16-byte binary UUIDv7
     * @param non-empty-string $externalLineRef
     */
    public function findByExternalRef(string $orderId, string $externalLineRef): ?OrderLine;

    /**
     * All lines for one order, ordered by surrogate id ascending (insertion order).
     *
     * @param string $orderId 16-byte binary UUIDv7
     *
     * @return list<OrderLine>
     */
    public function forOrder(string $orderId): array;

    /**
     * Insert or update one line.
     *
     * Same semantics as {@see OrderRepository::save()}.
     */
    public function save(OrderLine $line): OrderLine;

    /**
     * Insert or update many lines in a single bulk operation (one INSERT for new rows, one
     * deadlock-safe upsert for keyed rows) — never a per-line save loop.
     *
     * @param list<OrderLine> $lines
     *
     * @return list<OrderLine> the same records, with ids populated
     */
    public function saveAll(array $lines): array;

    /**
     * Delete the given lines in a single bulk operation — never a per-line delete loop.
     *
     * For projection reconciliation: a line whose source line item no longer exists in the
     * source system, and which carries no dispatch history (`qty_shipped`, `qty_corrected`
     * and `qty_staged` all zero), is removed outright so the projection mirrors its source
     * document. A line *with* history is never deleted — the caller zeroes its remainder
     * instead, because shipped and corrected quantities are facts about work performed and
     * survive the disappearance of the demand that caused them.
     *
     * @param list<OrderLine> $lines records with populated ids; an empty list is a no-op
     */
    public function deleteAll(array $lines): void;

    /**
     * Count the order lines for a subject that still have work outstanding
     * (`qty_outstanding > 0`) — the deletion guard's `open_order_lines` check.
     *
     * A line drops out of this count when the order is fulfilled or cancelled
     * (either zeroes its outstanding quantity), so a positive result means the
     * subject is referenced by at least one genuinely open order. One aggregate
     * query; never a per-line scan.
     */
    public function countOutstandingForSubject(SubjectId $subjectId): int;
}
