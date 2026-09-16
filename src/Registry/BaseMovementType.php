<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Registry;

use Nandan108\InvFlux\Domain\Procurement\ProcurementMovementType;

/**
 * The movement types every InvFlux install has, whatever platform it sits on.
 *
 * A movement type is the ledger's **reason vocabulary** — why stock moved, as opposed to how (the
 * flow) or where (the slots). None of these reasons are WooCommerce's: reserving against a cart,
 * booking a confirmed order, clearing on dispatch, correcting a count, reconciling against the
 * host's own number. A second adapter does the same things for the same reasons.
 *
 * ## Why the owner key is neutral
 *
 * A movement type is identified by `(owner_key, code)`. Registering these under a per-host key
 * (`WooBase`, `PsFree`) makes `reserve` a different type on every platform, which buys nothing:
 * **only one adapter ever runs on an install**, so a WordPress site has no PrestaShop movements to
 * be confused with. What it costs is that every adapter re-declares the same set and then drifts —
 * which had already happened before this class existed, in both directions.
 *
 * The one case where two hosts genuinely share a ledger is federation, and there the discriminator
 * is the *system*, which the identity registry already carries. Overloading the owner key to
 * half-answer that question would leave the real one still unanswered.
 *
 * Add-ons keep their own owner keys — that is what the key is for: an add-on's types are its own,
 * and survive its removal so the ledger rows pointing at them still read.
 *
 * @api
 */
final class BaseMovementType
{
    /**
     * Owner of every type below.
     *
     * Names the product, not the platform. Add-ons register under their own key; only the base set
     * lives here.
     */
    public const OWNER_KEY = 'invflux';

    // ── Order lifecycle ──────────────────────────────────────────────────────────────────────
    public const RESERVE = 'reserve';
    public const RELEASE = 'release';
    public const BOOK_RESERVED = 'book_reserved';
    public const BOOK_RECOVERED = 'book_recovered';
    public const CLEAR_CTD = 'clear_dispatched_sold';

    // ── Corrections ──────────────────────────────────────────────────────────────────────────
    public const CORRECTION_RESTOCK = 'correction_restock';
    public const CORRECTION_WRITEOFF_CTD = 'correction_writeoff_ctd';
    public const CORRECTION_RESTOCK_CREATE = 'correction_restock_create';

    /**
     * The same physical movement as a correction restock, under its own code because the movement
     * type is a *reason*: "the host's own document withdrew the demand" is a different why than
     * "an InvFlux correction released it", and a report that merges them answers neither question.
     * The same reasoning splits the refund and cancel restocks below.
     */
    public const HOST_EDIT_RELEASE = 'host_edit_release';

    /**
     * Commitment released because the host deleted the order outright. Not a correction and not a
     * cancellation: a deletion leaves no order to refund against, so it must not share a code with
     * the restocks that imply one.
     */
    public const HOST_DELETE_RELEASE = 'host_delete_release';
    public const REFUND_RESTOCK = 'refund_restock';
    public const CANCEL_RESTOCK = 'cancel_restock';

    // ── Operator adjustments and drift ───────────────────────────────────────────────────────
    public const STOCK_ADJUST_ATP = 'stock_adjust_forsale';
    public const ONHAND_CORRECTION = 'onhand_correction';

    /**
     * Settling a drift between InvFlux and the host's own stock number.
     *
     * Deliberately not host-prefixed. Which host is a property of the install, not of the reason —
     * and a code that names one makes the same reason unsearchable across a family of adapters.
     */
    public const RECONCILE = 'reconcile';

    /**
     * The definitions to register, labels included.
     *
     * An adapter registers these and adds whatever it genuinely owns; it does not restate them.
     *
     * @return list<MovementTypeDefinition>
     */
    public static function definitions(): array
    {
        return [
            new MovementTypeDefinition(self::RESERVE, 'Reserve'),
            new MovementTypeDefinition(self::RELEASE, 'Release'),
            new MovementTypeDefinition(self::BOOK_RESERVED, 'Book reserved'),
            new MovementTypeDefinition(self::BOOK_RECOVERED, 'Book recovered'),
            new MovementTypeDefinition(self::CLEAR_CTD, 'Clear sold-on-dispatch'),
            new MovementTypeDefinition(self::CORRECTION_RESTOCK, 'Correction restock (ctd → atp)'),
            new MovementTypeDefinition(self::CORRECTION_WRITEOFF_CTD, 'Correction write-off (ctd → nil)'),
            new MovementTypeDefinition(self::CORRECTION_RESTOCK_CREATE, 'Foreign-refund restock create (nil → atp, post-dispatch)'),
            new MovementTypeDefinition(self::HOST_EDIT_RELEASE, 'Host order edit release (ctd → atp)'),
            new MovementTypeDefinition(self::HOST_DELETE_RELEASE, 'Host order deletion release (ctd → atp)'),
            new MovementTypeDefinition(self::REFUND_RESTOCK, 'Refund restock (ctd → atp)'),
            new MovementTypeDefinition(self::CANCEL_RESTOCK, 'Cancel-of-paid restock (ctd → atp)'),
            new MovementTypeDefinition(self::STOCK_ADJUST_ATP, 'Stock adjustment (free stock)'),
            new MovementTypeDefinition(self::ONHAND_CORRECTION, 'On-hand stock correction'),
            new MovementTypeDefinition(self::RECONCILE, 'Reconciliation with the host'),
            new MovementTypeDefinition(ProcurementMovementType::PO_RECEIPT, 'Purchase order receipt (→ oh.atp)'),
            new MovementTypeDefinition(ProcurementMovementType::STOCK_INTAKE, 'Stock intake, no order (→ oh.atp)'),
        ];
    }
}
