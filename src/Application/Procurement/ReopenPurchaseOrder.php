<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Application\Procurement;

use Nandan108\InvFlux\Domain\Procurement\InvalidPurchaseOrderTransition;
use Nandan108\InvFlux\Domain\Procurement\PoStatus;
use Nandan108\InvFlux\Domain\Procurement\PurchaseOrder;
use Nandan108\InvFlux\Domain\Procurement\PurchaseOrderLifecycle;
use Nandan108\InvFlux\Domain\Procurement\PurchaseOrderRepository;

/**
 * Reopen a received order for receiving: a receipt corrected down after the order completed left
 * units open, and the supplier is sending them. The buyer chooses this; a correction never reopens
 * an order by itself. Units that are not coming are closed out instead ({@see CloseShortReceipt}),
 * and the order stays received.
 *
 * The order lands where its lines put it: partially received while anything stays received, in
 * transit when a correction took everything back. Either way the next delivery is received as usual,
 * and the order's completion date is cleared until it completes again. The transition's event
 * carries `{reopened: true}`, so the timeline says what happened rather than showing a plain move.
 *
 * @api
 */
final class ReopenPurchaseOrder
{
    private readonly PurchaseOrderLifecycle $lifecycle;

    public function __construct(
        private readonly PurchaseOrderRepository $purchaseOrders,
        ?PurchaseOrderLifecycle $lifecycle = null,
    ) {
        $this->lifecycle = $lifecycle ?? new PurchaseOrderLifecycle();
    }

    /**
     * @throws InvalidPurchaseOrderTransition when the order is not received, or nothing is open on it
     */
    public function __invoke(PurchaseOrder $po, ?int $actorId = null, ?string $note = null): PurchaseOrder
    {
        $target = $this->receivedAnything($po) ? PoStatus::PartiallyReceived : PoStatus::InTransit;
        if (PoStatus::Received !== $po->status) {
            throw new InvalidPurchaseOrderTransition($po->status, $target);
        }

        return (new TransitionPurchaseOrder($this->purchaseOrders, $this->lifecycle))(
            $po,
            $target,
            actorId: $actorId,
            note: null === $note || '' === trim($note) ? null : $note,
            payload: ['reopened' => true],
        );
    }

    private function receivedAnything(PurchaseOrder $po): bool
    {
        foreach (null === $po->id ? [] : $this->purchaseOrders->linesForPurchaseOrder($po->id) as $line) {
            if ($line->qty_received > 0 || $line->qty_damaged > 0) {
                return true;
            }
        }

        return false;
    }
}
