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

    /**
     * A dispatch undone: the Ship was confirmed by mistake and the goods never left, so the units
     * the dispatch cleared are committed to the order again. Its own code because it is its own
     * reason — neither a return (the goods did not come back; they never went) nor a correction.
     */
    public const DISPATCH_REVERSAL = 'dispatch_reversal';

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
     * The mirror of {@see HOST_EDIT_RELEASE}: the host's own document *raised* its demand, so the
     * commitment grows to match. Physically a recovered book (`atp → ctd`), under its own code for
     * the same reason the release has one — "the host's document asked for more" is a different why
     * than "an order was recovered from cancellation", and a report that merges them answers
     * neither.
     *
     * Capped at what `atp` actually holds. A document may ask for more than exists, and the mirror
     * of the quantity stands regardless: the shortfall is then real demand the order cannot cover,
     * which is a deficit to surface rather than a movement to invent.
     */
    public const HOST_EDIT_BOOK = 'host_edit_book';

    /**
     * The host's document raised what an order still on a checkout reservation asks for, so the
     * reservation grows to match (`atp → res`). Its own code rather than `reserve` for the reason
     * the two codes above have theirs: "the shopper checked out" and "someone edited the order
     * afterwards" are different whys. Free stock only — it never takes another order's hold.
     *
     * A decrease on such an order needs no code of its own: it is {@see HOST_EDIT_RELEASE}, and the
     * ledger row's source slot already says the units left `res` rather than `ctd`.
     */
    public const HOST_EDIT_RESERVE = 'host_edit_reserve';

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
     * **A label is merchant-facing copy, so it carries no slot keys and no host's name.** It is read
     * in a stock history by someone who knows what a goods receipt is and has no reason to know what
     * `ctd` is or which adapter wrote the row — the slot pair is already the movement's own columns,
     * and naming a host here is what made two adapters drift apart before this set moved to core.
     *
     * @return list<MovementTypeDefinition>
     */
    public static function definitions(): array
    {
        return [
            new MovementTypeDefinition(self::RESERVE, 'Reserve'),
            new MovementTypeDefinition(self::RELEASE, 'Reservation released'),
            new MovementTypeDefinition(self::BOOK_RESERVED, 'Reservation committed'),
            new MovementTypeDefinition(self::BOOK_RECOVERED, 'Free stock committed'),
            new MovementTypeDefinition(self::CLEAR_CTD, 'Order shipped'),
            new MovementTypeDefinition(self::DISPATCH_REVERSAL, 'Shipment reversed'),
            new MovementTypeDefinition(self::CORRECTION_RESTOCK, 'Correction restock'),
            new MovementTypeDefinition(self::CORRECTION_WRITEOFF_CTD, 'Correction write-off'),
            new MovementTypeDefinition(self::CORRECTION_RESTOCK_CREATE, 'Restock after an external refund'),
            new MovementTypeDefinition(self::HOST_EDIT_RELEASE, 'Order edited — stock released'),
            new MovementTypeDefinition(self::HOST_EDIT_BOOK, 'Order edited — stock committed'),
            new MovementTypeDefinition(self::HOST_EDIT_RESERVE, 'Order edited — stock reserved'),
            new MovementTypeDefinition(self::HOST_DELETE_RELEASE, 'Order deleted — stock released'),
            new MovementTypeDefinition(self::REFUND_RESTOCK, 'Refund restock'),
            new MovementTypeDefinition(self::CANCEL_RESTOCK, 'Cancelled order restock'),
            new MovementTypeDefinition(self::STOCK_ADJUST_ATP, 'Stock adjustment (free stock)'),
            new MovementTypeDefinition(self::ONHAND_CORRECTION, 'On-hand stock correction'),
            new MovementTypeDefinition(self::RECONCILE, 'Stock reconciliation'),
            new MovementTypeDefinition(ProcurementMovementType::PO_RECEIPT, 'Goods receipt'),
            new MovementTypeDefinition(ProcurementMovementType::STOCK_INTAKE, 'Stock intake (no purchase order)'),
            new MovementTypeDefinition(ProcurementMovementType::RECEIPT_CORRECTION, 'Goods receipt correction'),
        ];
    }
}
