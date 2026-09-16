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
use Nandan108\Attrecord\Enum\RelationType;
use Nandan108\Attrecord\Exception\RecordValidationException;
use Nandan108\Attrecord\Record;
use Nandan108\InvFlux\Domain\Subject\Subject;

/**
 * One line of a {@see GoodsReceipt} — the source of truth for what arrived. Against a
 * {@see PurchaseOrderLine} when the receipt answers an order, and against nothing when it does not:
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
#[LockTier(36)]
final class ReceiptLine extends Record
{
    #[Column(ColumnType::IntUnsigned, autoIncrement: true)]
    public ?int $id = null;

    #[Column(ColumnType::IntUnsigned)]
    #[Index('idx_receipt')]
    public int $receipt_id = 0;

    /**
     * The ordered line this delivery counts against, when there is one. NULL on a source-less
     * intake, which has no ordered quantity to reconcile with — that absence is the whole difference
     * between receiving against an expectation and simply bringing stock in.
     */
    #[Column(ColumnType::IntUnsigned, nullable: true)]
    #[Index('idx_po_line')]
    public ?int $po_line_id = null;

    /**
     * What arrived. Denormalized from the PO line where there is one, for ledger/WAC writes without
     * a join — and stated directly where there is not, since a source-less intake names its own
     * subject.
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
    #[Column(ColumnType::Decimal, precision: 10, scale: 4, nullable: true)]
    public ?string $unit_cost_snapshot = null;

    #[Column(ColumnType::DateTime, precision: 6)]
    public ?\DateTimeImmutable $created_at = null;

    #[Relation(
        RelationType::ManyToOne,
        class: GoodsReceipt::class,
        foreignKey: 'receipt_id',
        onDelete: ForeignKeyAction::Cascade,
    )]
    public ?GoodsReceipt $receipt = null;

    /** Null on a source-less intake, and on any line whose order line has yet to be resolved. */
    #[Relation(
        RelationType::ManyToOne,
        class: PurchaseOrderLine::class,
        foreignKey: 'po_line_id',
        onDelete: ForeignKeyAction::Restrict,
    )]
    public ?PurchaseOrderLine $purchaseOrderLine = null;

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
        // po_line_id is nullable: a source-less intake counts against no ordered line. When it is
        // set it must still be a real id — 0 would be a silent "no line" wearing a line's clothes.
        if (null !== $this->po_line_id && $this->po_line_id <= 0) {
            throw new RecordValidationException(
                'ReceiptLine.po_line_id must be a positive integer when set.',
                ['field' => 'po_line_id', 'value' => $this->po_line_id],
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
