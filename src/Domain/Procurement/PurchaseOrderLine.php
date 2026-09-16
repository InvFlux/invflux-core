<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Domain\Procurement;

use Nandan108\Attrecord\Attribute\Column;
use Nandan108\Attrecord\Attribute\Index;
use Nandan108\Attrecord\Attribute\LockTier;
use Nandan108\Attrecord\Attribute\Relation;
use Nandan108\Attrecord\Attribute\Table;
use Nandan108\Attrecord\Enum\ColumnType;
use Nandan108\Attrecord\Enum\ForeignKeyAction;
use Nandan108\Attrecord\Enum\GeneratedColumnMode;
use Nandan108\Attrecord\Enum\RelationType;
use Nandan108\Attrecord\Exception\RecordValidationException;
use Nandan108\Attrecord\Record;
use Nandan108\InvFlux\Domain\Subject\Subject;

/**
 * One PO line — a per-SKU (subject) order quantity on a {@see PurchaseOrder}.
 *
 * `qty_received` is an app-maintained rollup of `receipt_lines.qty`, bumped inside the
 * receipt transaction (cheap reads, drift-defended). `qty_open` is a DB-generated VIRTUAL
 * column mirroring {@see \Nandan108\InvFlux\Domain\Order\OrderLine::$qty_outstanding}.
 *
 * No PO-line ↔ order-line FK (the SO→PO link is out of scope; demand→supply rides the
 * `sup.atp` reservation + the ledger PO ref).
 *
 * @api
 *
 * @psalm-suppress PossiblyUnusedProperty Properties are hydrated by attrecord from row data.
 */
#[Table(name: 'invflux_po_lines')]
#[LockTier(34)]
final class PurchaseOrderLine extends Record
{
    /**
     * Identities for {@see $expected_source}. Numbers are stable labels, **not** an authority
     * ranking — see that property.
     *
     * The set is closed and core's: each names a *kind of document or act* that can establish what
     * is coming, and an add-on that introduces a new one is claiming an authority the arbitration
     * has to know about, which is not something to discover from an unrecognised integer.
     */
    public const EXPECTED_SOURCE_ORDERED = 1;
    public const EXPECTED_SOURCE_MANUAL = 2;
    public const EXPECTED_SOURCE_ORDER_ACK = 3;
    public const EXPECTED_SOURCE_SHIPMENT = 4;
    public const EXPECTED_SOURCE_INVOICE = 5;

    #[Column(ColumnType::IntUnsigned, autoIncrement: true)]
    public ?int $id = null;

    #[Column(ColumnType::IntUnsigned)]
    #[Index('idx_po')]
    public int $po_id = 0;

    #[Column(ColumnType::IntUnsigned)]
    #[Index('idx_subject')]
    public int $subject_id = 0;

    #[Column(ColumnType::IntUnsigned)]
    public int $qty_requested = 0;

    /**
     * What the supplier *confirmed* they'd ship (from an ASN / email / order confirmation) — the
     * operational variance baseline (`received − expected`). Essentials: typed manually; Pro: auto-populated
     * by an ASN. Null = not yet known (variance then falls back to `qty_requested`). Schema reserved
     * ahead of the variance UI.
     */
    #[Column(ColumnType::IntUnsigned, nullable: true)]
    public ?int $qty_expected = null;

    /**
     * Where {@see $qty_expected} came from — and therefore **who is allowed to write it next**.
     *
     * `qty_expected` is a single merged slot, not a history: successive supplier documents overwrite
     * it. That is fine while one party maintains it, and becomes a silent conflict the moment two
     * do — an add-on deriving it from shipment documents cannot otherwise tell its own roll-up from
     * a figure a buyer typed in this morning, and each would keep overwriting the other with no
     * symptom but a number that will not sit still.
     *
     * So the invariant is **at most one authority per line at a time**, and this column is what
     * makes it observable rather than inferred. A holder writes `qty_expected` only where this names
     * itself or is null; anything else is a hand-off that has to be deliberate.
     *
     * **The stored value is an identity, never a rank.** Sources *are* ranked — a shipment notice
     * outranks an order acknowledgement, being both later and more specific — but encoding that
     * ranking as the stored number would silently re-rank historical rows the first time the policy
     * changed. Rank is a policy applied to the identity, and it lives in
     * {@see ExpectedSourceAuthority}, which is what a writer asks before claiming.
     *
     * Null means nobody has claimed it: `qty_expected` is either unset, or was set before this
     * column existed.
     */
    #[Column(ColumnType::TinyIntUnsigned, nullable: true)]
    public ?int $expected_source = null;

    #[Column(ColumnType::IntUnsigned, nullable: true)]
    public ?int $qty_invoiced = null;

    /** App-maintained rollup of receipt_lines.qty for this line, bumped in the receipt txn. */
    #[Column(ColumnType::IntUnsigned, default: 0, comment: 'app-maintained rollup of receipt_lines.qty; recompute = SUM(receipt_lines.qty WHERE po_line_id = this)')]
    public int $qty_received = 0;

    /**
     * App-maintained rollup of `receipt_lines.damaged_qty` for this line, bumped in the same receipt
     * txn as {@see $qty_received}. Kept separate because `qty_received` is *good* (saleable) units only:
     * damaged arrivals are recorded here but never move into stock. Surfaces the cumulative
     * damaged-so-far on the receive grid (shown beside received-so-far) without a per-line SUM at read.
     */
    #[Column(ColumnType::IntUnsigned, default: 0, comment: 'app-maintained rollup of receipt_lines.damaged_qty; recompute = SUM(receipt_lines.damaged_qty WHERE po_line_id = this)')]
    public int $qty_damaged = 0;

    /**
     * Remainder written off by a close-short finalize (the supplier won't send the rest). Recorded
     * rather than silently dropped, and kept separate from `qty_requested` so the order quantity (what
     * we actually ordered) stays an untouched audit fact while `qty_open` still zeroes out. Default 0;
     * only a close-short sets it — the column is reserved ahead of the behaviour that writes it.
     */
    #[Column(ColumnType::IntUnsigned, default: 0, comment: 'qty written off by close-short; subtracted in qty_open so a short PO reads as fully resolved')]
    public int $qty_closed_short = 0;

    /**
     * Outstanding quantity still to receive — DB-computed, read-only. Subtracts both what arrived
     * (`qty_received`) and what was written off short (`qty_closed_short`), so a closed-short line
     * resolves to 0 open without touching `qty_requested`.
     *
     * The operands are CAST to SIGNED before subtracting: all three columns are `INT UNSIGNED`, so a
     * legitimate over-receipt (`qty_received > qty_requested` — supplier over-ships) would underflow
     * unsigned arithmetic. `GREATEST(0, …)` clamps the *result* at read time, but the intermediate
     * subtraction still range-errors during a table rebuild (e.g. `ADD COLUMN`); signing the operands
     * keeps it well-defined at every stage.
     *
     * @psalm-suppress UnusedProperty hydrated by the DB on every read; no PHP write path.
     */
    #[Column(
        ColumnType::IntUnsigned,
        generatedAs: 'GREATEST(0, CAST(qty_requested AS SIGNED) - CAST(qty_received AS SIGNED) - CAST(qty_closed_short AS SIGNED))',
        generatedMode: GeneratedColumnMode::Virtual,
    )]
    public int $qty_open = 0;

    /**
     * Supplier quote at PO time, in the PO currency — **always the net price**, after any line
     * discount. Null = inherit the supplier catalogue price until the order is issued.
     */
    #[Column(ColumnType::Decimal, precision: 10, scale: 4, nullable: true)]
    public ?string $unit_cost = null;

    /**
     * The supplier's price before their line discount, in the PO currency. Set only together with
     * {@see $discount_pct}, and {@see $unit_cost} is then exactly the net {@see LinePrice} computes from
     * the two — they record how the net was reached, for the document and the operator, and nothing
     * values stock from them.
     */
    #[Column(ColumnType::Decimal, precision: 10, scale: 4, nullable: true)]
    public ?string $list_unit_cost = null;

    /** The supplier's percentage discount off {@see $list_unit_cost}, 0 up to (not including) 100. */
    #[Column(ColumnType::Decimal, precision: 5, scale: 2, nullable: true)]
    public ?string $discount_pct = null;

    /**
     * What the supplier has most recently charged per unit for this line, in the PO currency —
     * maintained from {@see SupplierInvoiceLine}. Null until an invoice says otherwise.
     *
     * **A current rate, not an aggregate**, and that distinction is the whole design. Where several
     * invoices cover one line at different prices, this holds the latest; it never averages them.
     * Averaging is not needed and would be wrong here, because the blend happens downstream and at a
     * finer grain: each goods receipt freezes the rate standing when it is posted into its own
     * `ReceiptLine::$unit_cost_snapshot`, and the subject's weighted-average cost is computed across
     * those snapshots. A part delivery invoiced at one price and the rest at another therefore value
     * correctly on their own, with nobody doing the arithmetic. Contrast {@see $qty_invoiced}, which
     * *is* cumulative because "how many units have we been billed for" is a running total.
     *
     * The corollary is a discipline, not a bug: a receipt posted before its invoice arrives freezes
     * the ordered price, and a later invoice does not reach back — the weighted average is computed
     * at receipt and never revisited. Correcting a receipt's valuation after the fact is a
     * revaluation movement, which belongs to Pro / the costing bridge and deliberately not here.
     *
     * **Valuation reads only.** {@see effectiveUnitCost()} is for the receipt and for cost display;
     * the purchase-order *document* must keep quoting {@see $unit_cost}, or a price the supplier
     * invoiced would appear on the order they were sent.
     */
    #[Column(ColumnType::Decimal, precision: 10, scale: 4, nullable: true)]
    public ?string $unit_cost_invoiced = null;

    /**
     * Per-line VAT/GST rate override (percentage); null = inherit, resolving
     * `line ?? po ?? supplier.default_tax_rate`. A mixed-rate basket is ordinary (reduced-rate goods
     * beside standard-rate ones), so the override is what lets one order state each share correctly
     * rather than blending to a single misleading figure.
     */
    #[Column(ColumnType::Decimal, precision: 5, scale: 2, nullable: true)]
    public ?string $tax_rate = null;

    /**
     * Optional free-text line note — buyer→supplier instruction shown on the PO document (e.g.
     * "substitute if OOS", "do not split"). Draft-editable; surfaced via an opt-in column.
     */
    #[Column(ColumnType::VarChar, length: 500, nullable: true)]
    public ?string $note = null;

    #[Column(ColumnType::DateTime, precision: 6)]
    public ?\DateTimeImmutable $created_at = null;

    #[Column(ColumnType::DateTime, precision: 6)]
    public ?\DateTimeImmutable $updated_at = null;

    #[Relation(
        RelationType::ManyToOne,
        class: PurchaseOrder::class,
        foreignKey: 'po_id',
        onDelete: ForeignKeyAction::Cascade,
    )]
    public ?PurchaseOrder $purchaseOrder = null;

    #[Relation(
        RelationType::ManyToOne,
        class: Subject::class,
        foreignKey: 'subject_id',
        onDelete: ForeignKeyAction::Restrict,
    )]
    public ?Subject $subject = null;

    /**
     * The supplier-confirmed **expected** quantity, falling back to what was ordered (`qty_requested`)
     * when no OA/ASN has been recorded. Equivalently {@see VarianceLens::Expected}'s baseline; kept as a
     * named accessor because it is the common case. `qty_invoiced` is a separate billing / 3-way-match
     * axis (Pro) and is deliberately not used here.
     */
    public function expectedQty(): int
    {
        return $this->qty_expected ?? $this->qty_requested;
    }

    /**
     * The best figure available for what a unit of this line costs — what the supplier has invoiced
     * if they have said, else what was agreed when the order was raised.
     *
     * **For valuation, never for the document.** A goods receipt freezes this into its own line, and
     * the cost columns of an operator's grid read it. The purchase order the supplier holds must
     * keep quoting {@see $unit_cost}: printing back a price they invoiced would rewrite a document
     * they already have, and make the order say something it never said.
     */
    public function effectiveUnitCost(): ?string
    {
        return $this->unit_cost_invoiced ?? $this->unit_cost;
    }

    /** The line's price as one value: its net, and the list price and discount it came from. */
    public function price(): LinePrice
    {
        return new LinePrice($this->unit_cost, $this->list_unit_cost, $this->discount_pct);
    }

    /** Store a price reached through {@see LinePrice}, keeping the three columns coherent. */
    public function applyPrice(LinePrice $price): void
    {
        $this->unit_cost = $price->net;
        $this->list_unit_cost = $price->list;
        $this->discount_pct = $price->discountPct;
    }

    /** The baseline quantity for a given delivery-variance {@see VarianceLens}. */
    public function baselineQty(VarianceLens $lens): int
    {
        return VarianceLens::Ordered === $lens ? $this->qty_requested : $this->expectedQty();
    }

    /**
     * Signed **delivery** variance under `$lens`: `qty_received − baseline`. Positive = over-delivery,
     * negative = under (a shortfall while open, or the close-short amount once finalized). `qty_received`
     * is good (saleable) units only — damaged arrivals never count toward it.
     */
    public function deliveryVarianceQty(VarianceLens $lens = VarianceLens::Expected): int
    {
        return $this->qty_received - $this->baselineQty($lens);
    }

    /**
     * Classify the line's **delivery** variance under `$lens` ({@see VarianceStatus}) — received vs the
     * chosen baseline. Reception is progressive, so `finalized` is "nothing left open" (`qty_open === 0`):
     * an under-baseline line still reads `Open` while more is expected, `Short` once closed.
     */
    public function deliveryStatus(VarianceLens $lens = VarianceLens::Expected): VarianceStatus
    {
        return VarianceStatus::classify($this->baselineQty($lens), $this->qty_received, 0 === $this->qty_open);
    }

    /**
     * Signed **confirmation** variance: `expectedQty() − qty_requested` — what the supplier confirmed
     * (OA/ASN) against what was ordered. 0 until an OA/ASN is recorded (expected falls back to ordered).
     */
    public function confirmationVarianceQty(): int
    {
        return $this->expectedQty() - $this->qty_requested;
    }

    /**
     * Classify the line's **confirmation** variance ({@see VarianceStatus}) — supplier-confirmed vs
     * ordered, at the submit/in-transit stage. Non-progressive (a confirmation is what it is), so it is
     * always `finalized`: an under-order confirmation reads `Short`, never `Open`.
     */
    public function confirmationStatus(): VarianceStatus
    {
        return VarianceStatus::classify($this->qty_requested, $this->expectedQty(), true);
    }

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
        if ($this->po_id <= 0) {
            throw new RecordValidationException(
                'PurchaseOrderLine.po_id must be a positive integer.',
                ['field' => 'po_id', 'value' => $this->po_id],
            );
        }
        if ($this->subject_id <= 0) {
            throw new RecordValidationException(
                'PurchaseOrderLine.subject_id must be a positive integer.',
                ['field' => 'subject_id', 'value' => $this->subject_id],
            );
        }
        if (!LinePrice::isCoherent($this->unit_cost, $this->list_unit_cost, $this->discount_pct)) {
            throw new RecordValidationException(
                'PurchaseOrderLine.unit_cost must be the net of list_unit_cost less discount_pct, and both or neither must be set.',
                ['field' => 'discount_pct', 'value' => $this->discount_pct],
            );
        }
    }
}
