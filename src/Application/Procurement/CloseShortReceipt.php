<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Application\Procurement;

use Nandan108\InvFlux\Domain\Procurement\PoEvent;
use Nandan108\InvFlux\Domain\Procurement\PoStatus;
use Nandan108\InvFlux\Domain\Procurement\PurchaseOrder;
use Nandan108\InvFlux\Domain\Procurement\PurchaseOrderLifecycle;
use Nandan108\InvFlux\Domain\Procurement\PurchaseOrderLine;
use Nandan108\InvFlux\Domain\Procurement\PurchaseOrderRepository;

/**
 * Close a receiving session **short**: the supplier won't send the rest, so write off each line's
 * outstanding quantity and finalize the PO to `received`. The remainder is *recorded* (not silently
 * dropped) — `qty_closed_short` absorbs the open qty so `qty_open` resolves to 0 without mutating
 * `qty_requested` (the order stays an untouched audit fact), and a {@see PoEvent::TYPE_SHORT_CLOSED}
 * event captures the shorted lines + the reason for the future supplier scorecard.
 *
 * No stock movement: the written-off units never arrived, so nothing touches inventory — this is a
 * pure PO-line bookkeeping update + lifecycle transition. The receipt(s) that *did* arrive were already
 * committed by {@see ReceiveGoods} in earlier calls. This is the Essentials single-receipt resolution of an
 * under-delivery.
 *
 * The caller guarantees the PO is `in_reception` and that at least one line is outstanding (the empty
 * case is a full receipt — finalize via the normal transition, not this).
 *
 * @api
 */
final class CloseShortReceipt
{
    /**
     * Why the remainder won't arrive — a closed enum (structured for the future supplier scorecard).
     * Free-text detail belongs in the receipt `note`, not here. Unknown values coerce to `other`.
     */
    public const REASON_SUPPLIER_OOS = 'supplier_oos';
    public const REASON_PACKING_ERROR = 'packing_error';
    public const REASON_DISCONTINUED = 'discontinued';
    public const REASON_WONT_SHIP = 'wont_ship';
    public const REASON_OTHER = 'other';

    /** @var list<non-empty-string> */
    public const REASONS = [self::REASON_SUPPLIER_OOS, self::REASON_PACKING_ERROR, self::REASON_DISCONTINUED, self::REASON_WONT_SHIP, self::REASON_OTHER];

    private readonly PurchaseOrderLifecycle $lifecycle;

    /**
     * @param PurchaseOrderLifecycle|null $lifecycle the status graph to validate against; defaults to a
     *                                               fresh Essentials graph. A close-short finalizes to `received`,
     *                                               an Essentials edge, so the default suffices here.
     */
    public function __construct(
        private readonly PurchaseOrderRepository $purchaseOrders,
        ?PurchaseOrderLifecycle $lifecycle = null,
    ) {
        $this->lifecycle = $lifecycle ?? new PurchaseOrderLifecycle();
    }

    /**
     * @param list<PurchaseOrderLine> $lines  the PO's lines (authoritative quantities)
     * @param string                  $reason one of {@see self::REASONS}; anything else coerces to `other`
     * @param string|null             $note   optional free-text detail recorded on the event
     *
     * @throws \Nandan108\InvFlux\Domain\Procurement\InvalidPurchaseOrderTransition if not `in_reception`
     */
    public function __invoke(
        PurchaseOrder $po,
        array $lines,
        ?int $actorId = null,
        string $reason = self::REASON_OTHER,
        ?string $note = null,
    ): PurchaseOrder {
        $reason = \in_array($reason, self::REASONS, true) ? $reason : self::REASON_OTHER;
        // Validate the lifecycle edge up front (the close-short finalizes to received).
        $this->lifecycle->assertCanTransition($po->status, PoStatus::Received);

        // Write off each line's outstanding qty. Read qty_open BEFORE bumping qty_closed_short (the
        // generated column is recomputed by the DB on the next read, not in PHP).
        $dirty = [];
        $shorted = [];
        foreach ($lines as $line) {
            $open = $line->qty_open;
            if ($open <= 0) {
                continue;
            }
            $line->qty_closed_short += $open;
            $dirty[] = $line;
            $shorted[] = ['po_line_id' => (int) $line->id, 'qty' => $open];
        }

        if ([] !== $dirty) {
            $this->purchaseOrders->savePurchaseOrderLines($dirty);
        }

        // Finalize to received, landing a po.short_closed event (the close-short is the completion
        // signal; consumers key "stock is in" on the status, not the event type).
        $po->status = PoStatus::Received;
        $po->received_at = new \DateTimeImmutable();

        $event = PoEvent::newWith([
            'po_id'      => $po->id,
            'event_type' => PoEvent::TYPE_SHORT_CLOSED,
            'actor_id'   => $actorId,
            'note'       => null === $note || '' === trim($note) ? null : trim($note),
            'payload'    => json_encode(['reason' => $reason, 'lines' => $shorted], JSON_THROW_ON_ERROR),
        ]);

        return $this->purchaseOrders->transitionPurchaseOrder($po, $event);
    }
}
