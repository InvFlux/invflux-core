<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Domain\Procurement;

/**
 * The purchase-order status graph — the single source of truth for which transitions are legal
 * and what {@see PoEvent} each one emits. Pure (no I/O, no deps): apply/persist is the
 * {@see \Nandan108\InvFlux\Application\Procurement\TransitionPurchaseOrder} use-case's job; this
 * class only answers "is `from → to` allowed?" and "what event_type does landing on `to` emit?".
 *
 * A fresh instance self-seeds the full edge set in its constructor. It is not a static map:
 * add-ons contribute further edges at runtime via {@see registerTransition()} (and, if a target
 * needs an event name not already reserved here, {@see registerEventType()}). Resolve it once as a
 * container singleton and hand the same instance to the transition use-cases, so a contribution made
 * after they are built is still visible (the map is mutated in place). This is the seam a future
 * inbound-shipment (advance-notice) add-on uses to layer its own reconciliation edges on top.
 *
 * Edges (base spine). Transitions are forward-only, plus cancel/archive: cancellation is barred once
 * goods are received; archival is the terminal soft-delete reachable from received and cancelled too.
 * ACKNOWLEDGED(9) is an optional supplier-OA waypoint — the manual flip is base, and SUBMITTED →
 * IN_TRANSIT still skips it when OA isn't tracked.
 *
 *   IN_PREP(1)             → SUBMITTED(7), CANCELLED(19), ARCHIVED(21)
 *   SUBMITTED(7)           → ACKNOWLEDGED(9), IN_TRANSIT(11), CANCELLED(19), ARCHIVED(21)
 *   ACKNOWLEDGED(9)        → IN_TRANSIT(11), CANCELLED(19), ARCHIVED(21)
 *   IN_TRANSIT(11)         → IN_RECEPTION(13), CANCELLED(19), ARCHIVED(21)
 *   IN_RECEPTION(13)       → RECEIVED(17), PARTIALLY_RECEIVED(15), IN_TRANSIT(11), CANCELLED(19), ARCHIVED(21)
 *                            [→IN_TRANSIT = cancel an unproductive reception (nothing counted); back to pre-receive]
 *   PARTIALLY_RECEIVED(15) → IN_RECEPTION(13), RECEIVED(17)
 *   RECEIVED(17)           → ARCHIVED(21)
 *   CANCELLED(19)          → ARCHIVED(21)
 *   ARCHIVED(21)           → (terminal)
 *
 * Review states IN_REVIEW(3) / APPROVED(5) are schema-present but carry no base edges: the review
 * add-on contributes the IN_PREP → IN_REVIEW → APPROVED → SUBMITTED path plus the refuse/reopen exits
 * (both landing on IN_PREP) via {@see registerTransition()}. Their landing event_type names are
 * reserved in the map below so the add-on rarely needs {@see registerEventType()}; the refuse/reopen
 * edge events are edge-scoped and emitted by the add-on's own action, since two distinct edges landing
 * on the shared IN_PREP target can't both be keyed here.
 *
 * PARTIALLY_RECEIVED(11) — "keep a PO open across deliveries". Receiving one delivery of a split
 * shipment and parking the PO until the next is recording what physically arrived, so the whole
 * cycle is base behaviour: the *entry* edge (IN_RECEPTION → PARTIALLY_RECEIVED), the *reopen* exit
 * (→ IN_RECEPTION for the next delivery) and the *finalize* exit (→ RECEIVED, a final receipt or a
 * close-short) are all seeded here. Not cancellable once entered — goods are already in. What an
 * add-on layers on top is not this state but the *advance-shipment-notice* document (a governed
 * declaration each receipt reconciles against); the successive-receipt state machine itself is base.
 *
 * @api
 */
final class PurchaseOrderLifecycle
{
    /**
     * status backing int => list of statuses it may transition to. Fully seeded here;
     * add-ons append via {@see registerTransition()}.
     *
     * @var array<int, list<PoStatus>>
     */
    private array $transitions;

    /**
     * Target status backing int => `po_events.event_type` emitted when landing on it.
     *
     * @var array<int, non-empty-string>
     */
    private array $eventTypes;

    public function __construct()
    {
        $this->transitions = [
            PoStatus::InPrep->value => [
                PoStatus::Submitted,
                PoStatus::Cancelled,
            ],
            PoStatus::Submitted->value => [
                // Optional supplier-OA waypoint; the direct edge to in_transit stays for installs that
                // don't track acknowledgements.
                PoStatus::Acknowledged,
                PoStatus::InTransit,
                // Goods can turn up before anyone records that they shipped — an unannounced
                // delivery against an open order is ordinary, not exceptional. The edge exists so
                // the arrival is recorded as what it is, rather than forcing a shipment event that
                // nobody observed in order to reach reception. Whether a site *permits* opening a
                // reception this early is a policy an add-on may impose; the lifecycle only says it
                // is not a contradiction.
                PoStatus::InReception,
                PoStatus::Cancelled,
            ],
            PoStatus::Acknowledged->value => [
                PoStatus::InTransit,
                // Same reason as above: acknowledged and already at the door is a normal sequence.
                PoStatus::InReception,
                PoStatus::Cancelled,
            ],
            PoStatus::InTransit->value => [
                PoStatus::InReception,
                PoStatus::Cancelled,
            ],
            PoStatus::InReception->value => [
                PoStatus::Received,
                // Keep the PO open for the next delivery of a split shipment: receive what arrived and
                // park in partially_received. Recording a partial arrival is correctness, so this is
                // base behaviour, not a gated one.
                PoStatus::PartiallyReceived,
                // Cancel an unproductive reception (opened by mistake / nothing arrived, nothing counted):
                // drop back to the resting in-transit state. The caller drops any staged WIP on the way out.
                // No goods have moved, so this is a pure status undo — not a cancellation.
                PoStatus::InTransit,
                PoStatus::Cancelled,
            ],
            // Wind-down exits from the keep-open state: reopen to receive the next delivery, or finalize
            // (a final receipt or a close-short both land on received). Not cancellable — goods are
            // already in.
            PoStatus::PartiallyReceived->value => [
                PoStatus::InReception,
                PoStatus::Received,
            ],
            // Terminal: an order that has been received or cancelled has nowhere left to go.
            // Filing it out of the working lists is not a move — see PurchaseOrder::$archived_at.
            PoStatus::Received->value  => [],
            PoStatus::Cancelled->value => [],
        ];

        $this->eventTypes = [
            // Reserved names for the review add-on's forward landing events (edges contributed at runtime).
            PoStatus::InReview->value           => 'po.submitted_for_review',
            PoStatus::Approved->value           => 'po.approved',
            PoStatus::Submitted->value          => 'po.submitted',
            PoStatus::Acknowledged->value       => 'po.acknowledged',
            PoStatus::InTransit->value          => 'po.in_transit',
            PoStatus::InReception->value        => 'po.reception_started',
            PoStatus::PartiallyReceived->value  => 'po.partially_received',
            PoStatus::Received->value           => 'po.received',
            PoStatus::Cancelled->value          => 'po.cancelled',
        ];
    }

    /**
     * Contribute a transition edge `$from → $to` (idempotent). Add-ons call this on
     * `invflux_container_built` against the shared lifecycle singleton.
     */
    public function registerTransition(PoStatus $from, PoStatus $to): void
    {
        $existing = $this->transitions[$from->value] ?? [];
        if (!\in_array($to, $existing, true)) {
            $existing[] = $to;
            $this->transitions[$from->value] = $existing;
        }
    }

    /**
     * Contribute (or override) the `po_events.event_type` emitted when landing on `$to`. Most Pro
     * targets already have a reserved name seeded above, so this is rarely needed — it exists so a
     * genuinely new add-on status can name its landing event without a core change.
     *
     * @param non-empty-string $eventType
     */
    public function registerEventType(PoStatus $to, string $eventType): void
    {
        $this->eventTypes[$to->value] = $eventType;
    }

    public function canTransition(PoStatus $from, PoStatus $to): bool
    {
        return \in_array($to, $this->transitions[$from->value] ?? [], true);
    }

    /**
     * @throws InvalidPurchaseOrderTransition when `from → to` is not a legal edge
     */
    public function assertCanTransition(PoStatus $from, PoStatus $to): void
    {
        if (!$this->canTransition($from, $to)) {
            throw new InvalidPurchaseOrderTransition($from, $to);
        }
    }

    /**
     * The statuses reachable from `$from` (for surfacing the available actions in the UI).
     *
     * @return list<PoStatus>
     */
    public function transitionsFrom(PoStatus $from): array
    {
        return $this->transitions[$from->value] ?? [];
    }

    /**
     * The `po_events.event_type` for landing on `$to`.
     *
     * @return non-empty-string
     *
     * @throws InvalidPurchaseOrderTransition when `$to` is not a recognised target status
     */
    public function eventTypeFor(PoStatus $to): string
    {
        return $this->eventTypes[$to->value] ?? throw new InvalidPurchaseOrderTransition(null, $to);
    }
}
