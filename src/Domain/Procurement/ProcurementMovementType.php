<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Domain\Procurement;

use Nandan108\InvFlux\Registry\BaseMovementType;

/**
 * Canonical movement-type codes for procurement stock movements.
 *
 * A PO receipt is not WooCommerce-specific, so the codes are core's and every host adapter
 * registers the same set under core's own owner key ({@see BaseMovementType::definitions()}).
 * A movement type is identified by `(owner_key, code)`, so two adapters spelling the same reason
 * under two different owners would split one figure across every report that groups by it.
 *
 * Which of these a receipt is recorded under is not a caller's choice: it comes from the flow
 * binding the receipt's event resolves to, owner key included, so an add-on that routes goods
 * receipt somewhere else records it under a type of its own without core knowing.
 *
 * The supplier-side lifecycle codes (`po_place`, `asn_confirm`, `putaway_*`) join this set when
 * the add-on that raises them ships.
 *
 * @api
 */
final class ProcurementMovementType
{
    /** Goods received directly into for-sale stock (`nil → {warehouse}.atp`) — Essentials goods receipt. */
    public const PO_RECEIPT = 'po_receipt';

    /**
     * Stock brought in with no document behind it — the same `nil → {warehouse}.atp` flow as a
     * receipt against an order, under its own code because the movement type is the ledger's
     * *reason* vocabulary: "a supplier delivered what we ordered" and "we opened a balance, found
     * units on a shelf, or built them ourselves" are different answers to why stock appeared, and a
     * report that adds them together is measuring nothing. The receipt's
     * {@see ReceiptReason} carries which of those it was.
     */
    public const STOCK_INTAKE = 'stock_intake';
}
