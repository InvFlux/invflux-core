<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Application\Procurement;

use Nandan108\InvFlux\Domain\Procurement\PoEvent;
use Nandan108\InvFlux\Domain\Procurement\PurchaseOrder;
use Nandan108\InvFlux\Domain\Procurement\PurchaseOrderRepository;

/**
 * File a purchase order out of the working lists, or put it back.
 *
 * **Deliberately not a {@see \Nandan108\InvFlux\Domain\Procurement\PoStatus} transition**, which is
 * why this sits beside {@see TransitionPurchaseOrder} rather than inside it. Filing an order away
 * says nothing about how the order turned out, so it neither reads nor writes `status`: a received
 * order that is filed away is still a received order, and is still shown as one wherever it is
 * shown. See {@see PurchaseOrder::$archived_at}.
 *
 * Two consequences fall out, and both are the point. There is no lifecycle edge to add for each
 * status, so **any order can be filed away from any state** — including the half-received resting
 * state, which a status-shaped version could never reach without an edge somebody remembered to
 * write. And it is reversible, because nothing was overwritten to do it.
 *
 * **Idempotent.** Filing away an order already filed away leaves the original timestamp and records
 * nothing: the audit trail says when it was put away, not how many times someone pressed the
 * button.
 */
final class ArchivePurchaseOrder
{
    public const EVENT_ARCHIVED = 'po.archived';
    public const EVENT_UNARCHIVED = 'po.unarchived';

    public function __construct(private readonly PurchaseOrderRepository $purchaseOrders)
    {
    }

    /**
     * @param bool $archived true to file the order away, false to put it back
     *
     * @return PurchaseOrder the saved order, or the untouched one when it was already in that state
     */
    public function __invoke(
        PurchaseOrder $po,
        bool $archived = true,
        ?int $actorId = null,
        ?string $note = null,
    ): PurchaseOrder {
        if ($archived === $po->isArchived()) {
            return $po;
        }

        $po->archived_at = $archived ? new \DateTimeImmutable() : null;

        $event = PoEvent::newWith([
            'po_id'      => $po->id,
            'event_type' => $archived ? self::EVENT_ARCHIVED : self::EVENT_UNARCHIVED,
            'actor_id'   => $actorId,
            'note'       => $note,
            'payload'    => null,
        ]);

        // The same write the status moves use: the order and its event land together, so the trail
        // can never disagree with the row about whether this happened.
        return $this->purchaseOrders->transitionPurchaseOrder($po, $event);
    }
}
