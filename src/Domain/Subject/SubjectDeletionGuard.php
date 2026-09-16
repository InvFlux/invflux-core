<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Domain\Subject;

use Nandan108\InvFlux\Contracts\Inventory\InventoryReader;
use Nandan108\InvFlux\Domain\Order\OrderLineRepository;
use Nandan108\InvFlux\Domain\Procurement\PurchaseOrderRepository;

/**
 * The inventory-integrity deletion invariant, as a pure predicate.
 *
 * > Deleting the platform record must never be a way to discard stock,
 * > commitments, or in-flight procedures.
 *
 * The host platform's product/variation record may be hard-deleted only once the
 * subject has **no inventory blockers**: any remaining stock has been written
 * off through its real workflow, and every open procedure referencing it is
 * closed. This is a **core** concern — every platform adapter inherits the same
 * rule, because the invariant is about stock, not about any one catalogue.
 *
 * The guard is deliberately read-only and side-effect-free: it computes a
 * verdict, it does not enforce it. Enforcement (map_meta_cap, pre_delete_post,
 * a REST endpoint, the variation JS shim) and the extensibility filter
 * (`invflux_subject_deletion_blockers`) live in the adapter, which composes
 * this verdict with add-on contributions.
 *
 * @api
 */
final class SubjectDeletionGuard
{
    /** Any inventory_state slot for the subject is non-zero — on-hand, transit, supplier-side, or committed. */
    public const TYPE_NON_ZERO_INVENTORY = 'non_zero_inventory';

    /** At least one order line still has qty_outstanding > 0. */
    public const TYPE_OPEN_ORDER_LINES = 'open_order_lines';

    /** At least one non-draft, non-terminal purchase order references the subject. */
    public const TYPE_OPEN_PROCUREMENT = 'open_procurement';

    /** Move the stock out / write it off through its real workflow. */
    public const REMEDIATION_WRITE_OFF_STOCK = 'write_off_or_move_stock';

    /** Fulfill (dispatch) or cancel the referencing orders. */
    public const REMEDIATION_CLOSE_ORDERS = 'fulfill_or_cancel_orders';

    /** Cancel or close the referencing purchase orders. */
    public const REMEDIATION_CLOSE_PROCUREMENT = 'close_or_cancel_purchase_orders';

    public function __construct(
        private readonly InventoryReader $inventory,
        private readonly OrderLineRepository $orderLines,
        private readonly PurchaseOrderRepository $purchaseOrders,
    ) {
    }

    /**
     * Compute the deletion verdict for a subject.
     *
     * Each check is one targeted aggregate query (never a per-row scan). The
     * three built-in blockers are:
     *
     * - `non_zero_inventory` — any active inventory_state slot with a non-zero
     *   quantity. Because the committed state (`ctd`) is itself a slot value,
     *   committed stock is already covered here, so the order-line check need
     *   only test the *outstanding* quantity.
     * - `open_order_lines` — any order line with `qty_outstanding > 0`.
     * - `open_procurement` — any non-draft, non-terminal PO referencing the
     *   subject (a submitted/in-transit/received-but-not-closed PO can still
     *   move quantity; a draft PO commits nothing and does not block).
     */
    public function evaluate(SubjectId $subjectId): DeletionVerdict
    {
        $blockers = [];

        $slots = $this->inventory->countNonZeroSlots($subjectId);
        if ($slots > 0) {
            $blockers[] = new DeletionBlocker(
                self::TYPE_NON_ZERO_INVENTORY,
                $slots,
                self::REMEDIATION_WRITE_OFF_STOCK,
            );
        }

        $lines = $this->orderLines->countOutstandingForSubject($subjectId);
        if ($lines > 0) {
            $blockers[] = new DeletionBlocker(
                self::TYPE_OPEN_ORDER_LINES,
                $lines,
                self::REMEDIATION_CLOSE_ORDERS,
            );
        }

        $pos = $this->purchaseOrders->countOpenProcurementForSubject($subjectId);
        if ($pos > 0) {
            $blockers[] = new DeletionBlocker(
                self::TYPE_OPEN_PROCUREMENT,
                $pos,
                self::REMEDIATION_CLOSE_PROCUREMENT,
            );
        }

        return new DeletionVerdict($blockers);
    }
}
