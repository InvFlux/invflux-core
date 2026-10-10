<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Application\Procurement;

use Nandan108\InvFlux\Domain\Procurement\InvalidPurchaseOrderTransition;
use Nandan108\InvFlux\Domain\Procurement\PoEvent;
use Nandan108\InvFlux\Domain\Procurement\PoStatus;
use Nandan108\InvFlux\Domain\Procurement\PurchaseOrder;
use Nandan108\InvFlux\Domain\Procurement\PurchaseOrderLifecycle;
use Nandan108\InvFlux\Domain\Procurement\PurchaseOrderRepository;

/**
 * Move a purchase order to a new lifecycle status: validate the edge against
 * {@see PurchaseOrderLifecycle}, set the status, and atomically persist it together with the
 * transition's {@see PoEvent} (the audit trail accrues from the start — the OrderEvents
 * principle). The repository commits the status change and the event in one transaction.
 *
 * Action-specific *guards* (e.g. "submit needs ≥1 line with qty > 0", "don't re-submit an
 * already-submitted PO") belong in the calling action, not here. This enforces the status graph,
 * plus the two rules the graph cannot express and no caller may skip, both about whether anything has
 * been received:
 * - **an order that has received anything cannot be canceled** — it finishes by a close-short. The
 *   edge map allows PARTIALLY_RECEIVED → ARRIVED → CANCELED, so without this check it would be legal;
 * - **a delivery refused at the dock rests as partially received only if something came before** —
 *   ARRIVED → PARTIALLY_RECEIVED on an order that never received anything would claim goods that
 *   never arrived; such an order goes back to in transit.
 * Platform-agnostic: the adapter supplies the acting actor id and any note/payload.
 *
 * @api
 */
final class TransitionPurchaseOrder
{
    private readonly PurchaseOrderLifecycle $lifecycle;

    /**
     * @param PurchaseOrderLifecycle|null $lifecycle the status graph to validate against; defaults to a
     *                                               fresh Essentials graph. Pass the container-resolved singleton
     *                                               to honor any add-on-contributed edges (e.g. Pro partial GR).
     */
    public function __construct(
        private readonly PurchaseOrderRepository $purchaseOrders,
        ?PurchaseOrderLifecycle $lifecycle = null,
    ) {
        $this->lifecycle = $lifecycle ?? new PurchaseOrderLifecycle();
    }

    /**
     * @param array<string, mixed>|null $payload structured context for the event (JSON-encoded)
     *
     * @throws \Nandan108\InvFlux\Domain\Procurement\InvalidPurchaseOrderTransition
     */
    public function __invoke(
        PurchaseOrder $po,
        PoStatus $toStatus,
        ?int $actorId = null,
        ?string $note = null,
        ?array $payload = null,
    ): PurchaseOrder {
        $this->lifecycle->assertCanTransition($po->status, $toStatus);
        if (PoStatus::Canceled === $toStatus && null !== $po->id && $this->hasReceivedGoods($po->id)) {
            throw new InvalidPurchaseOrderTransition($po->status, $toStatus, InvalidPurchaseOrderTransition::REASON_GOODS_RECEIVED);
        }
        if (PoStatus::Arrived === $po->status && PoStatus::PartiallyReceived === $toStatus
            && (null === $po->id || !$this->hasReceivedGoods($po->id))) {
            throw new InvalidPurchaseOrderTransition($po->status, $toStatus, InvalidPurchaseOrderTransition::REASON_NOTHING_RECEIVED);
        }

        $po->status = $toStatus;

        $event = PoEvent::newWith([
            'po_id'      => $po->id,
            'event_type' => $this->lifecycle->eventTypeFor($toStatus),
            'actor_id'   => $actorId,
            'note'       => $note,
            'payload'    => null === $payload ? null : json_encode($payload, JSON_THROW_ON_ERROR),
        ]);

        return $this->purchaseOrders->transitionPurchaseOrder($po, $event);
    }

    /** Whether any line has taken delivery of anything, saleable or damaged. */
    private function hasReceivedGoods(int $poId): bool
    {
        foreach ($this->purchaseOrders->linesForPurchaseOrder($poId) as $line) {
            if ($line->qty_received > 0 || $line->qty_damaged > 0) {
                return true;
            }
        }

        return false;
    }
}
