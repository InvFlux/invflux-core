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
     * Lines of one order, by their numbers within it.
     *
     * **Bulk is the operation; one line is its degraded case.** A repository read shaped around a
     * single key is an invitation to call it once per item, and the invitation gets accepted —
     * every singular read this interface used to offer was being called inside a loop. Shaping it
     * the other way round makes `n = 1` a wrap and an unwrap at the call site, where it is visible
     * and cheap, rather than making `n = 12` twelve round trips, where it is neither.
     *
     * Keyed by line number rather than returned as a list, because the caller asked by number and
     * will look up by number. Missing numbers are simply absent — asking for a line that is not
     * there is not an error, it is an answer.
     *
     * @param string    $orderId 16-byte binary UUIDv7
     * @param list<int> $lineIds numbers within that order; an empty list yields an empty map
     *
     * @return array<int, OrderLine> line number => line
     */
    public function findByKeys(string $orderId, array $lineIds): array;

    /**
     * Lines of one order, by their source-system line references.
     *
     * The `(order_id, external_line_ref)` natural key, in bulk for the reason above — the one
     * caller of its singular ancestor read a line per refunded item inside a `foreach`.
     *
     * @param string       $orderId          16-byte binary UUIDv7
     * @param list<string> $externalLineRefs an empty list yields an empty map
     *
     * @return array<string, OrderLine> external line ref => line
     */
    public function findByExternalRefs(string $orderId, array $externalLineRefs): array;

    /**
     * All lines for one order, in line-number order — which is insertion order, because numbers
     * are handed out by a counter that only moves forward.
     *
     * The ordering used to come from the surrogate being a UUIDv7 and therefore time-sortable.
     * Now it is the key's own second member, so the order is stated by the query rather than
     * inherited from how ids happened to be minted.
     *
     * @param string $orderId 16-byte binary UUIDv7
     *
     * @return list<OrderLine>
     */
    public function forOrder(string $orderId): array;

    // A singular save() used to sit here. It went the same way as the singular reads, and for the
    // same reason — its one caller wrote a line per correction inside a loop. Saving one line is
    // saveAll() with one element.

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
     * source system, and which nothing else refers to, is removed outright so the projection
     * mirrors its source document. A line *with* history is never deleted — the caller zeroes its
     * remainder instead, because shipped and corrected quantities are facts about work performed
     * and survive the disappearance of the demand that caused them.
     *
     * **Callers establish that with {@see referencedIds()}, not with the quantity counters.** The
     * counters are derived and can fall back to zero while the history they summarised is still
     * on file — a reinstated cancellation decrements `qty_corrected` and deliberately leaves the
     * original processed correction behind. The database refuses such a delete regardless (the
     * referring rows are RESTRICT), so a caller that skips the check gets an exception rather than
     * silence; the check is how it avoids provoking one.
     *
     * @param list<OrderLine> $lines records with a populated key; an empty list is a no-op
     */
    public function deleteAll(array $lines): void;

    /**
     * Which of the given lines something still refers to, as a set of `(order, line)` keys.
     *
     * The honest form of "does this line carry history": it asks the referring tables rather than
     * trusting a counter on the line to still summarise them. One bulk read per referring table,
     * never a per-line probe.
     *
     * Keys are returned in the encoding {@see OrderLine::keyOf()} produces, because a line number
     * is not unique on its own — a set keyed by number alone would report line 3 of one order as
     * referenced because line 3 of another is.
     *
     * @param list<OrderLine> $lines records with a populated key; an empty list yields an empty set
     *
     * @return array<string, true> `OrderLine::keyOf()` => true, for lookup by key
     */
    public function referencedIds(array $lines): array;

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
