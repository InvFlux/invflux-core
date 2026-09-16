<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Domain\Order;

/**
 * Append-only timeline of dispatch-scoped actions against an order.
 *
 * See arch-order-events for schema rationale,
 * event vocabulary, and invariants. The store has no `update` or `delete`; reversals
 * are appended as new events with the matching `correlationId` per §4.2 invariant 1.
 *
 * @api
 */
interface OrderEventStore
{
    /**
     * Append one event row. Returns the row populated with its assigned `id`.
     *
     * Implementations must:
     * - Enforce the table-is-append-only contract (no UPDATE/DELETE).
     * - When the caller passes an `OrderEvent` whose `recordedAt` is the construction
     *   default, override it server-side with `NOW()` so the column reflects the actual
     *   insert moment. `occurredAt` is preserved as-is.
     */
    public function append(OrderEvent $event): OrderEvent;

    /**
     * Most-recent-first timeline for one order. Default limit is generous enough for
     * the workbench Activity tab; reduce when streaming.
     *
     * @param string $orderId 16-byte binary UUIDv7
     *
     * @return list<OrderEvent>
     */
    public function forOrder(string $orderId, int $limit = 200): array;

    /**
     * All events sharing a `correlationId`, ordered by `occurredAt` ascending.
     *
     * Used by reversal workflows ("undo this batch") and by reports that need to walk
     * the events emitted by a single user action.
     *
     * @return list<OrderEvent>
     */
    public function byCorrelation(string $correlationId): array;

    /**
     * Auto-refund schedules that are due and not yet settled — the cron queue.
     *
     * Returns `refund.scheduled` events with `mode='auto'` whose `occurred_at <= $now`
     * (the schedule's `occurred_at` equals its `execute_at`) and which have NO terminal
     * sibling (`correction.refund_confirmed` or `refund.cancelled`) matching on
     * `correlation_id` + payload `scheduled_event_id`. `refund.failed` is non-terminal,
     * so a failed schedule is still returned (the caller applies the backoff gate).
     * `mode='manual'` schedules are intentionally excluded — they are settled only by
     * the operator, never auto-executed.
     *
     * @return list<OrderEvent>
     */
    public function findDueScheduledRefunds(\DateTimeImmutable $now, int $limit): array;
}
