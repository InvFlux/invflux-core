<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Domain\Procurement;

use Nandan108\Attrecord\Attribute\Column;
use Nandan108\Attrecord\Attribute\ForeignKey;
use Nandan108\Attrecord\Attribute\Index;
use Nandan108\Attrecord\Attribute\LockTier;
use Nandan108\Attrecord\Attribute\Relation;
use Nandan108\Attrecord\Attribute\Table;
use Nandan108\Attrecord\Enum\ColumnType;
use Nandan108\Attrecord\Enum\ForeignKeyAction;
use Nandan108\Attrecord\Enum\RelationType;
use Nandan108\Attrecord\Exception\RecordValidationException;
use Nandan108\Attrecord\Record;
use Nandan108\InvFlux\Domain\Subject\Subject;

/**
 * One line of a {@see GoodsReceipt} — the source of truth for what arrived. Against a
 * {@see PurchaseOrderLine} when a purchase order backs it, and against nothing when it does not:
 * a source-less intake states its own subject and cost, which is why {@see $subject_id} and
 * {@see $unit_cost_snapshot} live here rather than being read back through the order.
 * `qty` is the **good** (saleable) quantity that moved into
 * `oh.atp` and feeds `unit_cost_snapshot` → the WAC recompute on {@see SubjectCost}.
 * `damaged_qty` is the count received but unfit for sale: at Essentials it's a recorded number
 * only (seeds a supplier claim, surfaces `exception_flag`); whether Pro slots it (`oh.damaged`)
 * or writes it off is a disposition *policy*. Short/over are never stored — they're derived
 * from `qty_received` vs `qty_requested`.
 *
 * The receipt transaction also writes the `po_receipt` ledger movement (`nil → oh.atp`,
 * ref = PO) and bumps `po_lines.qty_received`.
 *
 * @api
 *
 * @psalm-suppress PossiblyUnusedProperty Properties are hydrated by attrecord from row data.
 */
#[Table(name: 'invflux_receipt_lines')]
// The ordered line this delivery counts against, named by the line's whole key. Because the
// product is part of it, a receipt line cannot count against a line for a different product, and
// because the order is, it cannot count against another order's line. When `po_id` is NULL the
// constraint is not checked at all (a composite foreign key with a NULL member is skipped, by the
// SQL standard's MATCH SIMPLE): that is intended, since NULL means this line is not backed by a
// purchase order — a source-less intake, or another backing.
#[ForeignKey(
    column: ['po_id', 'subject_id'],
    references: PurchaseOrderLine::class,
    onDelete: ForeignKeyAction::Restrict,
)]
#[Index('idx_po_line', columns: ['po_id', 'subject_id'])]
#[LockTier(36)]
final class ReceiptLine extends Record
{
    #[Column(ColumnType::IntUnsigned, autoIncrement: true)]
    public ?int $id = null;

    #[Column(ColumnType::IntUnsigned)]
    #[Index('idx_receipt')]
    public int $receipt_id = 0;

    /**
     * The purchase order this delivery counts against, when a purchase order backs it. With
     * {@see $subject_id} it names the ordered line. NULL when no purchase order does: a source-less
     * intake, which has no ordered quantity to reconcile with, or a receipt backed by something else.
     *
     * Carried per line rather than on the receipt, because a receipt's backing is not always one
     * order: a shipment notice can cover several, and the receipt against it inherits that.
     */
    #[Column(ColumnType::IntUnsigned, nullable: true)]
    public ?int $po_id = null;

    /**
     * What arrived. Against an order it is also the second half of the ordered line's key, so it
     * cannot disagree with that line's product; on a source-less intake it is stated directly.
     */
    #[Column(ColumnType::IntUnsigned)]
    public int $subject_id = 0;

    /** Good (saleable) units received — the quantity moved into `oh.atp`. */
    #[Column(ColumnType::IntUnsigned)]
    public int $qty = 0;

    /** Units received but damaged/unfit for sale (Essentials: recorded only; Pro disposition is policy). */
    #[Column(ColumnType::IntUnsigned, default: 0)]
    public int $damaged_qty = 0;

    /** Cost at receipt (in PO currency) — feeds the WAC recompute. */
    #[Column(ColumnType::Decimal, precision: 20, scale: 4, nullable: true)]
    public ?string $unit_cost_snapshot = null;

    /**
     * The product's total on hand just before this receipt moved it, as the weighting read it —
     * unclamped, so the quantity after (`on_hand_before` + the units this receipt moved for the
     * product) is exact even from a short position. A product-level figure: every line of the same
     * product on one receipt carries the same value. Null on a receipt posted before it was recorded.
     */
    #[Column(ColumnType::Int, nullable: true)]
    public ?int $on_hand_before = null;

    /**
     * The cost this receipt was weighted against, in the store base: the product's average cost, or
     * its seed cost when it had none yet; null when it had neither. With `on_hand_before`, the
     * receipt's own lines and its rate, it reproduces the average the receipt left through
     * {@see \Nandan108\InvFlux\Application\Costing\WeightedAverage::blend()} — the
     * same function that wrote it, so a reader cannot disagree with the posting. Product-level, like
     * `on_hand_before`.
     */
    #[Column(ColumnType::Decimal, precision: 20, scale: 4, nullable: true)]
    public ?string $cost_before = null;

    #[Column(ColumnType::DateTime, precision: 6)]
    public ?\DateTimeImmutable $created_at = null;

    #[Relation(
        RelationType::ManyToOne,
        class: GoodsReceipt::class,
        foreignKey: 'receipt_id',
        onDelete: ForeignKeyAction::Cascade,
    )]
    public ?GoodsReceipt $receipt = null;

    #[Relation(
        RelationType::ManyToOne,
        class: Subject::class,
        foreignKey: 'subject_id',
        onDelete: ForeignKeyAction::Restrict,
    )]
    public ?Subject $subject = null;

    #[\Override]
    public function beforeSave(): void
    {
        if (null === $this->created_at) {
            $this->created_at = new \DateTimeImmutable();
        }
    }

    #[\Override]
    public function validate(): void
    {
        // receipt_id is assigned by the repository's recordReceipt() after the parent
        // GoodsReceipt is saved, so it is 0 at construction and intentionally not
        // validated here — the NOT NULL column + FK enforce it at persistence.
        // po_id is nullable: a line not backed by a purchase order counts against no ordered line.
        // When it is set it must still be a real id — 0 would be a silent "no order" wearing an
        // order's clothes.
        if (null !== $this->po_id && $this->po_id <= 0) {
            throw new RecordValidationException(
                'ReceiptLine.po_id must be a positive integer when set.',
                ['field' => 'po_id', 'value' => $this->po_id],
            );
        }
        if ($this->subject_id <= 0) {
            throw new RecordValidationException(
                'ReceiptLine.subject_id must be a positive integer.',
                ['field' => 'subject_id', 'value' => $this->subject_id],
            );
        }
    }
}
