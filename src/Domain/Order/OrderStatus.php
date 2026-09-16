<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Domain\Order;

/**
 * Lifecycle status of an order from the dispatch workbench's perspective.
 *
 * Derived from `staged_count` / `shipped_count` / `line_count` /
 * `unprocessed_corrections` / `workflow_state` by the recompute rule
 * (see `Nandan108\InvFlux\Woo\Application\Dispatch\OrderStatusRecomputer`).
 * The values are ordered along the natural workflow path
 * (Untouched → Started → Staged → Shipped / Cancelled) so the queue's
 * `status` index drives sensible top-of-list ordering by raw int.
 *
 * Distinct from {@see OrderWorkflowState} (which gates whether the order
 * is visible in the active queue at all).
 *
 * @api
 */
enum OrderStatus: int
{
    /**
     * Lines exist but the operator hasn't touched any. `staged_count == 0
     * AND shipped_count == 0`. The "fresh in the queue" state.
     */
    case Untouched = 0;

    /**
     * Some staging or shipping has happened, but the order isn't ready to
     * ship as a whole. `(staged_count + shipped_count) > 0` AND not yet
     * `Staged` / `Shipped`. Covers partial-stage, partial-ship, and
     * blocked-by-correction cases.
     */
    case Started = 1;

    /**
     * All remaining shippable work is staged and no blockers remain
     * (`staged_count + shipped_count == line_count`, no unprocessed
     * corrections, workflow == Active). The "ready to push the
     * Ship button" state.
     */
    case Staged = 2;

    /**
     * No shippable quantity remains on any stock-managed line. Per line, the
     * resolution can be any mix: fully shipped, fully corrected, or part of
     * each (e.g., 2 of 3 shipped, the remaining 1 `PM`-missing then refunded).
     * The invariant is `qty_ordered − qty_shipped − qty_corrected == 0` on
     * every line. Terminal positive state.
     */
    case Shipped = 3;

    /** Cancelled before fulfilment (matched against external order status). */
    case Cancelled = 4;
}
