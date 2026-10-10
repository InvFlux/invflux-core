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
 * Edges (base spine). Transitions are forward-only, plus cancellation, which is barred once goods
 * are received. Filing an order away is not a status but {@see PurchaseOrder::$archived_at}, so it
 * appears nowhere below. ACKNOWLEDGED(9) and ARRIVED(13) are optional waypoints: the manual flips are
 * base, and the direct edges around them stay for shops that do not record them.
 *
 *   IN_PREP(1)             → SUBMITTED(7), CANCELED(21)
 *   SUBMITTED(7)           → ACKNOWLEDGED(9), IN_TRANSIT(11), ARRIVED(13), IN_RECEPTION(15), CANCELED(21)
 *   ACKNOWLEDGED(9)        → IN_TRANSIT(11), ARRIVED(13), IN_RECEPTION(15), CANCELED(21)
 *   IN_TRANSIT(11)         → ARRIVED(13), IN_RECEPTION(15), CANCELED(21)
 *   ARRIVED(13)            → IN_RECEPTION(15), IN_TRANSIT(11), PARTIALLY_RECEIVED(17), CANCELED(21)
 *                            [→IN_TRANSIT / →PARTIALLY_RECEIVED = delivery refused at the dock: back to
 *                             waiting, partially_received when earlier deliveries already came in]
 *   IN_RECEPTION(15)       → RECEIVED(19), PARTIALLY_RECEIVED(17), ARRIVED(13), IN_TRANSIT(11), CANCELED(21)
 *                            [→ARRIVED / →IN_TRANSIT = abandon an unproductive reception (nothing counted):
 *                             back to the dock if the goods had arrived, else back to transit]
 *   PARTIALLY_RECEIVED(17) → ARRIVED(13), IN_RECEPTION(15), RECEIVED(19)
 *
 * The map cannot say whether anything was received, and ARRIVED is reachable from PARTIALLY_RECEIVED,
 * so two routes need a check the map cannot make: PARTIALLY_RECEIVED → ARRIVED → CANCELED (or on
 * through IN_TRANSIT) would cancel an order whose goods are already in, and ARRIVED →
 * PARTIALLY_RECEIVED on an order that never received anything would claim goods that never came.
 * {@see \Nandan108\InvFlux\Application\Procurement\TransitionPurchaseOrder} refuses any move to
 * CANCELED once a line has received anything (an order with goods in finishes by a close-short), and
 * ARRIVED → PARTIALLY_RECEIVED while no line has.
 *   RECEIVED(19)           → (terminal)
 *   CANCELED(21)           → (terminal)
 *
 * Review states IN_REVIEW(3) / APPROVED(5) are schema-present but carry no base edges: the review
 * add-on contributes the IN_PREP → IN_REVIEW → APPROVED → SUBMITTED path plus the refuse/reopen exits
 * (both landing on IN_PREP) via {@see registerTransition()}. Their landing event_type names are
 * reserved in the map below so the add-on rarely needs {@see registerEventType()}; the refuse/reopen
 * edge events are edge-scoped and emitted by the add-on's own action, since two distinct edges landing
 * on the shared IN_PREP target can't both be keyed here.
 *
 * PARTIALLY_RECEIVED(17) — "keep a PO open across deliveries". Receiving one delivery of a split
 * shipment and parking the PO until the next is recording what physically arrived, so the whole
 * cycle is base behavior: the *entry* edge (IN_RECEPTION → PARTIALLY_RECEIVED), the *reopen* exit
 * (→ IN_RECEPTION for the next delivery, or → ARRIVED when that delivery is recorded at the dock
 * before it is counted) and the *finalize* exit (→ RECEIVED, a final receipt or a
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
                PoStatus::Canceled,
            ],
            PoStatus::Submitted->value => [
                // Optional supplier-OA waypoint; the direct edge to in_transit stays for installs that
                // don't track acknowledgements.
                PoStatus::Acknowledged,
                PoStatus::InTransit,
                // An unannounced delivery recorded at the dock, before it is counted (same reasoning
                // as the direct edge to in_reception below).
                PoStatus::Arrived,
                // Goods can turn up before anyone records that they shipped — an unannounced
                // delivery against an open order is ordinary, not exceptional. The edge exists so
                // the arrival is recorded as what it is, rather than forcing a shipment event that
                // nobody observed in order to reach reception. Whether a site *permits* opening a
                // reception this early is a policy an add-on may impose; the lifecycle only says it
                // is not a contradiction.
                PoStatus::InReception,
                PoStatus::Canceled,
            ],
            PoStatus::Acknowledged->value => [
                PoStatus::InTransit,
                PoStatus::Arrived,
                // Same reason as above: acknowledged and already at the door is a normal sequence.
                PoStatus::InReception,
                PoStatus::Canceled,
            ],
            PoStatus::InTransit->value => [
                // The goods are at the dock, not yet counted: an event, never a stock movement.
                PoStatus::Arrived,
                PoStatus::InReception,
                PoStatus::Canceled,
            ],
            // Counting starts, or the delivery is refused at the dock. Nothing entered on-hand on
            // arrival, so a refusal is a cancellation with no stock to undo.
            PoStatus::Arrived->value => [
                PoStatus::InReception,
                // This delivery is refused at the dock and the supplier sends a replacement: the
                // order carries on and only the delivery goes back. Nothing was counted, so no stock
                // moves.
                PoStatus::InTransit,
                // The same refusal on an order whose earlier deliveries came in: back to its resting
                // state, from which the buyer waits for a replacement or closes short. Only when
                // something was received (enforced by TransitionPurchaseOrder).
                PoStatus::PartiallyReceived,
                PoStatus::Canceled,
            ],
            PoStatus::InReception->value => [
                PoStatus::Received,
                // Keep the PO open for the next delivery of a split shipment: receive what arrived and
                // park in partially_received. Recording a partial arrival is correctness, so this is
                // base behavior, not a gated one.
                PoStatus::PartiallyReceived,
                // Abandon an unproductive reception (opened by mistake, nothing counted): drop back to
                // where the goods are — the dock when they had arrived, otherwise in transit. The caller
                // picks which from the order's history and drops any staged WIP on the way out. No goods
                // have moved, so this is a pure status undo — not a cancellation.
                PoStatus::Arrived,
                PoStatus::InTransit,
                PoStatus::Canceled,
            ],
            // Wind-down exits from the keep-open state: reopen to receive the next delivery, or finalize
            // (a final receipt or a close-short both land on received). Not cancellable — goods are
            // already in.
            PoStatus::PartiallyReceived->value => [
                // The next delivery of a split shipment, recorded at the dock before it is counted.
                PoStatus::Arrived,
                PoStatus::InReception,
                PoStatus::Received,
            ],
            // Terminal: an order that has been received or canceled has nowhere left to go.
            // Filing it out of the working lists is not a move — see PurchaseOrder::$archived_at.
            PoStatus::Received->value  => [],
            PoStatus::Canceled->value => [],
        ];

        $this->eventTypes = [
            // Reserved names for the review add-on's forward landing events (edges contributed at runtime).
            PoStatus::InReview->value           => 'po.submitted_for_review',
            PoStatus::Approved->value           => 'po.approved',
            PoStatus::Submitted->value          => 'po.submitted',
            PoStatus::Acknowledged->value       => 'po.acknowledged',
            PoStatus::InTransit->value          => 'po.in_transit',
            PoStatus::Arrived->value            => 'po.arrived',
            PoStatus::InReception->value        => 'po.reception_started',
            PoStatus::PartiallyReceived->value  => 'po.partially_received',
            PoStatus::Received->value           => 'po.received',
            PoStatus::Canceled->value           => 'po.canceled',
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
     * @throws InvalidPurchaseOrderTransition when `$to` is not a recognized target status
     */
    public function eventTypeFor(PoStatus $to): string
    {
        return $this->eventTypes[$to->value] ?? throw new InvalidPurchaseOrderTransition(null, $to);
    }
}
