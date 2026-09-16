<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Domain\Order;

/**
 * The vocabulary of order-events emitted to `invflux_order_events`.
 *
 * Add new cases as new event kinds appear. Consumers should switch on this enum and
 * tolerate unknown values.
 * The string value is what is persisted in the `event_type` column; never rely on the
 * enum case name.
 *
 * Every case has a {@see OrderEventTier} via {@see self::tier()} — the tier is a schema
 * invariant enforced at write time on {@see OrderEvent}.
 *
 * See arch-order-events for the full
 * vocabulary table and required payload keys per event type.
 *
 * @api
 */
enum OrderEventType: string
{
    // ── Order lifecycle ──────────────────────────────────────────────────────────────
    case OrderCreated = 'order.created';
    case OrderAssigned = 'order.assigned';
    case OrderParked = 'order.parked';
    case OrderUnparked = 'order.unparked';
    case OrderLocked = 'order.locked';
    case OrderUnlocked = 'order.unlocked';

    /**
     * The host order this projection mirrors was deleted at the source, so the projection
     * was retired. Terminal and one-way: the projection survives only as history, and its
     * lines stop contributing outstanding demand.
     */
    case OrderSourceDeleted = 'order.source_deleted';

    /**
     * The host document changed a line's quantity on a booked order: the projection mirrored
     * the gross (`qty_ordered` follows the document; it is the host's number) and the stock
     * side released the outstanding-delta back to `atp`. One event per edited line. The
     * released quantity rides the payload *and* the movement's own ledger row — the event is
     * the timeline entry, the ledger row is the stock authority; they reference, never
     * duplicate, each other's arithmetic.
     */
    case OrderLineEdited = 'order.line_edited';

    /**
     * One of the order's stated addresses was corrected — a typo, a missing line, or a customer
     * reached after a failed delivery.
     *
     * The order is repointed at a newly interned party and the superseded one is left untouched,
     * so this event is the record of the change rather than a note about it.
     *
     * One event per correction however many addresses moved, keyed by address: each carries the
     * party hash on both sides and a **sparse patch in the facts' own shape**, not the whole
     * fact-set. The hashes keep "is this the same address as that one" answerable across orders;
     * the patch is applied, not only read — merge `to` to move a state forward, `from` to move it
     * back — so an address's history is its current facts walked backward through the chain rather
     * than a copy stored on every entry. A reconstructed state must hash to the recorded `from`,
     * which is what makes a gap in the chain detectable.
     *
     * Nothing here is a foreign key — a party is a deduplication cache that a re-statement rebuilds
     * identically, and an event that could only be read by joining to one would be a timeline entry
     * with an expiry date.
     */
    case OrderAddressCorrected = 'order.address_corrected';

    /**
     * An order's stated address changed **outside InvFlux** — WooCommerce's own order screen, its
     * REST API, or any other holder of the host order.
     *
     * Its own type rather than a flag on {@see OrderAddressCorrected}, because provenance is the
     * question this entry exists to answer and two provenances under one type cannot be told apart
     * afterwards. The payload shape is identical, so one renderer serves both.
     *
     * Recorded by the projection rather than by a hook of its own: the projection already runs on
     * every writer's save, and it is the thing that notices, since it is what repoints the order at
     * the party the host now implies.
     */
    case OrderAddressChangedExternally = 'order.address_changed_externally';

    // ── Shipment ────────────────────────────────────────────────────────────────────
    case ShipmentPrepared = 'shipment.prepared';
    case ShipmentSent = 'shipment.sent';
    case ShipmentCancelled = 'shipment.cancelled';

    // ── Corrections ─────────────────────────────────────────────────────────────────
    case CorrectionCreated = 'correction.created';
    case CorrectionUnprocessed = 'correction.unprocessed';
    /**
     * Emitted from {@see OrderCorrection} mutations
     * while the correction is in the *proposed* phase (`parent_event_id IS NULL`).
     * Payload carries `{before, after, fields_changed}` so the dispatch / corrections
     * workbench timeline can replay the edit history during MPB-style proposed→
     * bundled review flows. Not emitted once a decision-tier event has bundled the
     * correction — by then it's an authoritative line item, not a draft.
     */
    case CorrectionEdited = 'correction.edited';

    /**
     * A correction withdrawn before it was ever processed.
     *
     * Recorded rather than erased, and the pairing is the point: a {@see self::CorrectionCreated}
     * with nothing after it cannot be told from one still waiting, so a delete that removed its own
     * trace would leave the log asserting a pending correction that no longer exists.
     *
     * The interesting fact is usually the **interval**, not the net effect. An unprocessed
     * correction moves no stock and issues no money — that is the whole reason the unprocessed
     * phase exists, so a floor worker can state an operational fact ("stock missing") without
     * taking the decision that costs money or reaches the customer. But a correction raised and
     * withdrawn three days later is exactly why an order sat un-dispatched for three days, and
     * without both ends that answer is unavailable.
     */
    case CorrectionDeleted = 'correction.deleted';

    /**
     * Emitted once per batch when the merchant processes an order's pending
     * corrections and a refund is issued (or attested). One event covers the
     * whole batch; its payload is the authoritative refund record.
     *
     * The terminal *success* sibling of a {@see self::RefundScheduled} event: it
     * records that the scheduled refund was actually issued (or attested). Payload:
     * - `refund_mode`: `auto` (gateway refund) | `manual_confirmed` (out-of-band)
     * - `refund_total`: decimal string actually refunded
     * - `wc_refund_id`: the `WC_Order_Refund` id (null when total was 0, or when the host order no
     *   longer exists)
     * - `host_order_missing`: present and true when an operator settled the refund on an order the
     *   host no longer has — their attestation is the only record of it
     * - `shipping_included`: bool
     * - `adjustment`: signed decimal applied to the batch, or null
     * - `line_items`: `[{type, sku, qty, unit_price, total, description}]` — the
     *   decomposition the dispatch timeline's "previously corrected" fold renders
     * - `correction_ids`: hex ids covered by the batch
     * - `actor_user_id`: who committed the batch
     * - `scheduled_event_id`: hex id of the originating `refund.scheduled` event
     *
     * Inherits the schedule's `correlation_id`; summed by `FetchOrderRefundSummary`
     * as the authoritative past-refund total.
     */
    case CorrectionRefundConfirmed = 'correction.refund_confirmed';

    // ── Decisions ───────────────────────────────────────────────────────────────────
    /**
     * Customer or system resolution of a partial-capture (LPSC) hold — cancel-fully
     * or accept-partial. Acts as `parent_event_id` for the corrections created in
     * the same resolution; carries `execute_at` and the chosen branch in payload.
     */
    case LpscResolved = 'lpsc.resolved';

    /**
     * Merchant decision to commit a batch of pending (unprocessed) corrections
     * together. Emitted once per batch by the dispatch-workbench "Process
     * corrections" action — slot movements for the individual corrections
     * still happen inside the same transaction, but the audit timeline sees
     * one decision event covering all of them.
     *
     * Payload carries `correction_ids` (the hex IDs processed in the batch)
     * so the timeline can drill from the decision to the constituent
     * proposed-tier `correction.created` events.
     */
    case CorrectionsProcessed = 'corrections.processed';

    /**
     * Durable refund *intent / authorisation* — the head of the refund queue. Emitted
     * by both the Essentials batch path and LPSC resolution when a refund is owed. An
     * `IssueScheduledRefund` execution turns it into a terminal sibling
     * ({@see self::CorrectionRefundConfirmed} on success, {@see self::RefundCancelled}
     * on give-up); {@see self::RefundFailed} is a non-terminal retry marker.
     *
     * `occurred_at == execute_at` so the indexed time scan is the due filter
     * (`recorded_at` keeps the real insert moment). Payload:
     * `{ order_hex, correction_ids[], mode: 'auto'|'manual', line_items[], total,
     *    currency, execute_at, batch_key }`. `mode=auto` is executed automatically
     * (sync at schedule + cron retry on transient failure); `mode=manual`
     * (non-refundable gateway) is left unsettled = a "pending manual refund" the
     * operator settles. `batch_key` is the `_invflux_refund_id` idempotency key.
     */
    case RefundScheduled = 'refund.scheduled';

    // ── Refunds ─────────────────────────────────────────────────────────────────────
    case RefundFailed = 'refund.failed';
    /**
     * A refund InvFlux did NOT issue — a WC-direct refund (operator refunded in the Woo
     * order screen), mirrored back so the adapter-agnostic refund read path stays complete.
     * Without it a foreign refund is invisible to `FetchOrderRefundSummary` and the
     * over-refund bound, and only WC's `wc_create_refund()` backstop catches the overage.
     * Array payload `{ refund_total, wc_refund_id, currency }`, summed identically to
     * {@see self::CorrectionRefundConfirmed} (Lifecycle, non-monetary).
     */
    case RefundExternal = 'refund.external';
    /**
     * Terminal "this schedule will not pay it" sibling of a {@see self::RefundScheduled}: an
     * operator's waive, a batch refused before it was processed (`reason: wc_order_missing` —
     * nothing was owed), or an automatic schedule handed to an operator (`reason:
     * handed_to_operator`, `successor_event_id` naming the manual schedule that still owes it).
     * A refund whose host order no longer exists is still owed, so it is never cancelled for
     * that alone. Distinct from a future `refund.voided` post-execution contra-entry.
     */
    case RefundCancelled = 'refund.cancelled';

    // ── Payments ────────────────────────────────────────────────────────────────────
    /**
     * An {@see OrderPayment} was recorded against the order — confirmed by a gateway, entered by an
     * operator, or inferred from the host order status. The payment row is the record, this event its
     * place on the timeline and what an accounting add-on posts from.
     *
     * Monetary: a {@see MonetaryEventPayload} whose `amount` / `currency` is what the payment brought
     * in the **order's** currency (`OrderPayment::$amount_order_ccy`), with the base-currency figure
     * resolved as of the day the money arrived. `extras`: `payment_id`, `source`, `method`,
     * `payment_amount` / `payment_currency` / `payment_fx_rate` (what arrived, before conversion),
     * `difference` / `difference_reason` (what it settles beyond what arrived), `transaction_ref`.
     */
    case PaymentRecorded = 'payment.recorded';
    /**
     * A recorded payment was voided — it stays on record and stops counting towards what the order
     * has been paid. Monetary: the same payload as the payment it voids, `extras.reason` added.
     */
    case PaymentVoided = 'payment.voided';

    // ── Communication ───────────────────────────────────────────────────────────────
    case NoteAdded = 'note.added';
    case EmailSent = 'email.sent';
    case EmailSuppressed = 'email.suppressed';

    // ── Stock issues ────────────────────────────────────────────────────────────────
    case StockIssueReported = 'stock_issue.reported';

    /**
     * The {@see OrderEventTier} this type belongs to. Append-time validation on
     * {@see OrderEvent} enforces that every persisted event matches this mapping.
     */
    public function tier(): OrderEventTier
    {
        return match ($this) {
            self::OrderCreated,
            self::OrderAssigned,
            self::OrderParked,
            self::OrderUnparked,
            self::OrderLocked,
            self::OrderUnlocked,
            self::OrderSourceDeleted,
            self::OrderLineEdited,
            self::OrderAddressCorrected,
            self::OrderAddressChangedExternally,
            self::ShipmentPrepared,
            self::ShipmentSent,
            self::ShipmentCancelled,
            self::CorrectionCreated,
            self::CorrectionUnprocessed,
            self::CorrectionEdited,
            self::CorrectionDeleted,
            self::CorrectionRefundConfirmed,
            self::RefundExternal,
            self::PaymentRecorded,
            self::PaymentVoided => OrderEventTier::Lifecycle,
            self::LpscResolved,
            self::CorrectionsProcessed,
            self::RefundScheduled => OrderEventTier::Decision,
            self::RefundFailed,
            self::RefundCancelled => OrderEventTier::Execution,
            self::NoteAdded,
            self::EmailSent,
            self::EmailSuppressed,
            self::StockIssueReported => OrderEventTier::Ancillary,
        };
    }

    /**
     * True for event types whose `payload` must be a {@see MonetaryEventPayload}
     * value-object. Enforced at write time in {@see OrderEvent::validate()}; consumed
     * by {@see OrderEventPayloadCaster} to pick the right hydration shape.
     *
     * Covers the Execution-tier refund outcome events and the payment events. Future monetary types
     * (e.g. `gl.posted`, supplier `claim.settled`) should be added here.
     */
    public function isMonetary(): bool
    {
        return match ($this) {
            self::RefundFailed,
            self::RefundCancelled,
            self::PaymentRecorded,
            self::PaymentVoided   => true,
            default               => false,
        };
    }
}
