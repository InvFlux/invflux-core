<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Domain\Procurement;

use Nandan108\Attrecord\Attribute\Column;
use Nandan108\Attrecord\Attribute\ForeignKey;
use Nandan108\Attrecord\Attribute\LockTier;
use Nandan108\Attrecord\Attribute\Relation;
use Nandan108\Attrecord\Attribute\Table;
use Nandan108\Attrecord\Attribute\UniqueKey;
use Nandan108\Attrecord\Caster\EnumCaster;
use Nandan108\Attrecord\Enum\ColumnType;
use Nandan108\Attrecord\Enum\ForeignKeyAction;
use Nandan108\Attrecord\Enum\RelationType;
use Nandan108\Attrecord\Exception\RecordValidationException;
use Nandan108\Attrecord\Record;

/**
 * A purchase order — the procurement aggregate root. One supplier, one currency.
 *
 * `number` is the human reference — the gapless document number produced by the active
 * {@see PoNumberScheme} (Essentials: `{prefix}{seq}` from a settings-configured prefix +
 * start value). It is minted at the explicit "Assign number" action (pre-send), NOT at create, so it
 * is NULL on a fresh draft and NOT validated here; everything internal keys on the surrogate `id`
 * (`INT UNSIGNED`, admin-minted, present from insert), never on `number`.
 *
 * @api
 *
 * @psalm-suppress PossiblyUnusedProperty Properties are hydrated by attrecord from row data.
 */
#[Table(name: 'invflux_pos')]
// Declared here rather than as a #[Relation] because it points at `invflux_terms.content_hash`, a
// unique column that is not that table's primary key. `ON UPDATE RESTRICT` is the whole mechanism:
// the target is a generated column, so editing a referenced row's text recomputes it, which is an
// implied parent-key update, which this refuses. CASCADE would let the edit succeed and rewrite
// every order's pointer to follow it — worse than no constraint at all.
#[ForeignKey(
    column: 'terms_hash',
    references: 'invflux_terms',
    referencesColumn: 'content_hash',
    onDelete: ForeignKeyAction::Restrict,
    onUpdate: ForeignKeyAction::Restrict,
)]
#[ForeignKey(
    column: 'terms_lineage_id',
    references: TermsLineage::class,
    onDelete: ForeignKeyAction::Restrict,
    onUpdate: ForeignKeyAction::Restrict,
)]
#[LockTier(33)]
final class PurchaseOrder extends Record
{
    #[Column(ColumnType::IntUnsigned, autoIncrement: true)]
    public ?int $id = null;

    /**
     * Human reference — the gapless document number, minted at the "Assign number" action (pre-send),
     * NULL until then. UNIQUE among issued numbers; MySQL permits any number of NULL drafts under the
     * unique index, so drafts coexist while every issued number stays unique.
     */
    #[Column(ColumnType::VarChar, length: 32, nullable: true)]
    #[UniqueKey('uk_po_number')]
    public ?string $number = null;

    #[Column(ColumnType::IntUnsigned)]
    public int $supplier_id = 0;

    /** Lifecycle status (see {@see PoStatus} + {@see PurchaseOrderLifecycle}). */
    #[Column(ColumnType::TinyIntUnsigned, default: PoStatus::InPrep)]
    #[EnumCaster(PoStatus::class)]
    public PoStatus $status = PoStatus::InPrep;

    /**
     * Revision number, bumped when a *submitted* PO is amended (Pro revision flow). Reserved
     * now to avoid schema churn; always 1 at Essentials. The PO `number` stays stable across
     * revisions — the revision is rendered as a suffix (e.g. "PO-1043 · r2").
     */
    #[Column(ColumnType::SmallIntUnsigned, default: 1)]
    public int $revision = 1;

    /** ISO-4217; one currency per PO. */
    #[Column(ColumnType::VarChar, length: 3)]
    public string $currency = '';

    // No PO-level FX rate: the rate is a receipt-time fact captured on GoodsReceipt.fx_rate (one rate per
    // delivery), so a PO-level rate would invite a "which rate is real?" divergence. A live provisional
    // base value for an open foreign-currency PO comes from the FX-rates reference table, not a stored PO
    // rate.

    /** Applied purchase VAT/GST rate (percentage), defaulted from the supplier; drives net→tax→gross totals. */
    #[Column(ColumnType::Decimal, precision: 5, scale: 2, nullable: true)]
    public ?string $tax_rate = null;

    /**
     * The supplier's tax regime **as it stood when this order was issued**, snapshotted at the same
     * gate as {@see $number} and {@see $issued_at} because they are one fact: the document the
     * supplier holds. Reading the supplier live instead would let a later change to their treatment
     * silently re-render an already-issued order under a different regime — the statutory mention on
     * the copy in the supplier's hands would no longer match ours.
     *
     * NULL on an un-issued draft, where there is no document yet and the live supplier value is the
     * right answer.
     */
    #[Column(ColumnType::Enum, nullable: true)]
    #[EnumCaster(SupplierTaxTreatment::class)]
    public ?SupplierTaxTreatment $tax_treatment = null;

    /**
     * Commercial delivery term — where the supplier's obligation ends and ours begins
     * ({@see Incoterm}), agreed per order. Meaningless without {@see $incoterm_place}: "FCA" states
     * nothing, "FCA Rotterdam" is a term. There is no supplier-level rung to inherit from: a
     * standing delivery term per supplier is the natural next step, and would make this an override
     * the way {@see $terms_lineage_id} already is.
     */
    #[Column(ColumnType::Enum, nullable: true)]
    #[EnumCaster(Incoterm::class)]
    public ?Incoterm $incoterm = null;

    /** The named place the Incoterm applies at ("Rotterdam", "our warehouse, Geneva"). */
    #[Column(ColumnType::VarChar, length: 100, nullable: true)]
    public ?string $incoterm_place = null;

    /**
     * How the goods travel — carrier or service ("DHL Express", "own truck"). Deliberately separate
     * from {@see $incoterm}: the term says who is *responsible* and until where, this says by what
     * means. Conflating them is the classic purchasing-document error.
     */
    #[Column(ColumnType::VarChar, length: 64, nullable: true)]
    public ?string $shipping_method = null;

    /**
     * The set of purchase terms this order carries, when it carries one other than its supplier's —
     * the most specific rung of `order ?? supplier ?? store`; null inherits.
     *
     * Followed until the order is numbered: a draft on a set picks up that set's later versions, and
     * numbering freezes whichever is current into {@see $terms_hash}. A one-off arrangement that
     * should not become anyone's standing terms is not a set at all — it goes straight into
     * {@see $terms_hash}.
     */
    #[Column(ColumnType::BigIntUnsigned, nullable: true)]
    public ?int $terms_lineage_id = null;

    /**
     * Expected delivery date (ETA) — drives the Essentials "Active POs" late-at-top sort. A **planned day**,
     * not a recorded instant: its natural precision is the date (a supplier commits to "the 20th"), so
     * it is a DATE, not a DATETIME. A specific delivery *time window* (when an appointment is booked)
     * belongs in a separate, nullable field (from–to, tz-explicit) — a Scale/receiving-ops addition —
     * not in this column's precision.
     */
    #[Column(ColumnType::Date, nullable: true)]
    public ?\DateTimeImmutable $expected_at = null;

    /** When receipt was finalized — a recorded fact (ordering matters), so a full-precision timestamp. */
    #[Column(ColumnType::DateTime, precision: 6, nullable: true)]
    public ?\DateTimeImmutable $received_at = null;

    /**
     * When this order was filed away, or null while it is in the working lists.
     *
     * **Filing an order away is not one of the ways it ends, so it is not a {@see PoStatus}.** The
     * status answers *how did this order turn out* — received, cancelled, still in flight — and an
     * order that is put out of sight still has an answer to that. Held in the status column it
     * would overwrite one: a received order that got archived could no longer be shown as received,
     * and nothing would remain to say that it ever was.
     *
     * Separating them buys three things a terminal status cannot. Filing away becomes reversible,
     * because the previous answer was never destroyed. It applies to any order in any state,
     * without the lifecycle growing an edge from each one and an author remembering to add it. And
     * a query that means "not in the working set" has to say `archived_at IS NULL` out loud, rather
     * than omitting a case from a match and reading as complete.
     *
     * Same shape as a retired {@see \Nandan108\InvFlux\Domain\Tag\Tag}: hidden from the lists, still
     * resolvable by id, never hard-deleted — the order's events reference it forever.
     *
     * **An archived order is out of the inbound picture** even where its status still says goods
     * are coming: {@see PurchaseOrderRepository} pairs this with
     * {@see PoStatus::isOpenForInbound()}, so filing away an in-transit order stops it counting
     * towards on-order quantities. That is the point of filing it — but it is why the honest way to
     * abandon an order someone might still deliver is to cancel it, and archive it afterwards.
     */
    #[Column(ColumnType::DateTime, precision: 6, nullable: true)]
    public ?\DateTimeImmutable $archived_at = null;

    /** Whether this order has been filed out of the working lists (kept, never deleted). */
    public function isArchived(): bool
    {
        return null !== $this->archived_at;
    }

    /**
     * When the order became a real document — stamped with {@see $number}, at the same gate, since
     * they are one fact. This is the **order date** a supplier reads and dates their payment terms
     * from; `created_at` is when someone started a draft, which may be weeks earlier and is nobody's
     * business outside this system. NULL while un-numbered, where there is no order date yet.
     */
    #[Column(ColumnType::DateTime, precision: 6, nullable: true)]
    public ?\DateTimeImmutable $issued_at = null;

    /**
     * The parties as they stood when this order was issued — us, the delivery address, the supplier
     * — each a pointer into the shared, append-only {@see DocumentParty} table, stamped at the same
     * gate as {@see $number}, {@see $issued_at} and {@see $tax_treatment}, because they are one
     * fact: the document the supplier holds.
     *
     * Without them every block of an issued order re-resolves on each render, so moving premises,
     * changing our tax registration, or a supplier correcting their own address would silently
     * rewrite a document already sent — and nothing on either copy would say it had changed. A
     * purchase order is a record of an agreement, not a live view of two address books.
     *
     * Three pointers rather than one, because they legitimately differ: ship-to diverges from buyer
     * once receiving locations carry their own address, and even at one location the buyer block
     * carries our tax identifier while the delivery block does not. Where two slots do name the same
     * facts they resolve to the *same* row, which is the dedup working as intended.
     *
     * All NULL on an un-issued draft, where there is no document yet and the live values are correct.
     * An order re-opened for editing after issue and re-stamped points at whichever rows its facts
     * then intern to — possibly the same ones — and its number does not change.
     *
     * Each holds a {@see DocumentParty} **content hash**, which is that table's primary key: the
     * pointers survive merging one installation into another untouched, because the key is derived
     * from the party's own facts rather than allocated by a sequence.
     */
    #[Column(ColumnType::BigInt, nullable: true)]
    public ?int $buyer_party_id = null;

    #[Column(ColumnType::BigInt, nullable: true)]
    public ?int $ship_to_party_id = null;

    #[Column(ColumnType::BigInt, nullable: true)]
    public ?int $supplier_party_id = null;

    /**
     * The terms this order was issued under, as the content hash of the interned {@see Terms} row —
     * stamped at the same gate as the number and the party pointers, and null on a draft unless it
     * states one-off terms.
     *
     * **The hash and nothing else.** A version pointer was considered and rejected: a dispute is
     * about the terms themselves, which are identical under however many administrative names, so
     * the label carries no contractual weight and a pointer to it would record a filing fact at the
     * cost of a column and a join. Nothing is lost, because whatever *prints* — including a public
     * reference where one is carried — is inside the digest, so the hash identifies exactly the
     * artefact the supplier received.
     *
     * **This is a pointer, not a copy, and the difference is enforced by the schema.** The
     * referencing key is `ON UPDATE RESTRICT`, and the interned row's hash is a generated column, so
     * editing the text a referenced row holds is refused by the database rather than by convention.
     * A copied paragraph could be quietly rewritten by anything with a connection.
     *
     * **Also where a one-off lives.** Terms written for this order alone are interned when the draft
     * is saved and pointed at from here, so numbering keeps them rather than resolving past them.
     * Otherwise this stays null until numbering fills it from {@see $terms_lineage_id}, the
     * supplier's set or the store's, in that order.
     */
    #[Column(ColumnType::BigInt, nullable: true)]
    public ?int $terms_hash = null;

    /** Denormalized cache of the line totals; recompute = SUM(po_lines: qty_requested * unit_cost). */
    #[Column(ColumnType::Decimal, precision: 12, scale: 4, nullable: true, comment: 'cache of SUM(po_lines.qty_requested * unit_cost)')]
    public ?string $total_amount = null;

    #[Column(ColumnType::Bool, default: false)]
    public bool $exception_flag = false;

    #[Column(ColumnType::IntUnsigned, nullable: true)]
    public ?int $created_by = null;

    #[Column(ColumnType::DateTime, precision: 6)]
    public ?\DateTimeImmutable $created_at = null;

    #[Column(ColumnType::DateTime, precision: 6)]
    public ?\DateTimeImmutable $updated_at = null;

    #[Relation(
        RelationType::ManyToOne,
        class: Supplier::class,
        foreignKey: 'supplier_id',
        onDelete: ForeignKeyAction::Restrict,
    )]
    public ?Supplier $supplier = null;

    // Restrict, not cascade: a party row is append-only and shared, and an issued document must never
    // lose what it stated. Nothing deletes these rows in practice; the constraint documents why.
    #[Relation(RelationType::ManyToOne, class: DocumentParty::class, foreignKey: 'buyer_party_id', onDelete: ForeignKeyAction::Restrict)]
    public ?DocumentParty $buyerParty = null;

    #[Relation(RelationType::ManyToOne, class: DocumentParty::class, foreignKey: 'ship_to_party_id', onDelete: ForeignKeyAction::Restrict)]
    public ?DocumentParty $shipToParty = null;

    #[Relation(RelationType::ManyToOne, class: DocumentParty::class, foreignKey: 'supplier_party_id', onDelete: ForeignKeyAction::Restrict)]
    public ?DocumentParty $supplierParty = null;

    #[\Override]
    public function beforeSave(): void
    {
        $now = new \DateTimeImmutable();
        $this->updated_at = $now;
        if (null === $this->created_at) {
            $this->created_at = $now;
        }
    }

    #[\Override]
    public function validate(): void
    {
        // `number` is minted later by the Assign-number action (PoNumberScheme) and is NULL until
        // then, so it is intentionally not validated here.
        if ($this->supplier_id <= 0) {
            throw new RecordValidationException(
                'PurchaseOrder.supplier_id must be a positive integer.',
                ['field' => 'supplier_id', 'value' => $this->supplier_id],
            );
        }
    }
}
