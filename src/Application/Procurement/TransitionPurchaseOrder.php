<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Application\Procurement;

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
 * already-submitted PO") belong in the calling action, not here — this enforces only the status
 * graph. Platform-agnostic: the adapter supplies the acting actor id and any note/payload.
 *
 * @api
 */
final class TransitionPurchaseOrder
{
    private readonly PurchaseOrderLifecycle $lifecycle;

    /**
     * @param PurchaseOrderLifecycle|null $lifecycle the status graph to validate against; defaults to a
     *                                               fresh Essentials graph. Pass the container-resolved singleton
     *                                               to honour any add-on-contributed edges (e.g. Pro partial GR).
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
}
