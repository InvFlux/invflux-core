<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Domain\Subject;

use Nandan108\Attrecord\Attribute\Column;
use Nandan108\Attrecord\Attribute\Index;
use Nandan108\Attrecord\Attribute\Relation;
use Nandan108\Attrecord\Attribute\Table;
use Nandan108\Attrecord\Caster\BitmaskCaster;
use Nandan108\Attrecord\Enum\ColumnType;
use Nandan108\Attrecord\Enum\ForeignKeyAction;
use Nandan108\Attrecord\Enum\RelationType;
use Nandan108\Attrecord\Exception\RecordValidationException;
use Nandan108\Attrecord\Record;

/**
 * One outstanding stock concern attributed to a subject.
 *
 * Source of truth for the dispatch workbench's per-order
 * stock-state badge: the *subject* carries the concern, not the
 * individual order line, because all order lines referencing the
 * subject share the same answer to "is there a deficit / location risk /
 * etc.?".
 *
 * Schema invariant: **no row = no concern** for the subject. The
 * drainer (`DispatchStockStateOutboxDrainer` in the adapter)
 * `UPSERT`s when concerns exist and `DELETE`s when they all clear, so
 * the table stays small (typically 0 to a handful of rows even on
 * busy stores).
 *
 * The dispatch read path joins `invflux_order_lines` → this table on
 * `subject_id` and aggregates per order with
 * `COALESCE(BIT_OR(bits), 0)` — orders touching no concerned subject
 * get 0 from the COALESCE. See
 *  for the full
 * model.
 *
 * @api
 *
 * @psalm-suppress PossiblyUnusedProperty Properties are hydrated by attrecord from row data.
 */
#[Table(name: 'invflux_subject_stock_concerns', primaryKey: 'subject_id')]
final class SubjectStockConcern extends Record
{
    /** FK to `invflux_subjects.id` and also the PK — one row per affected subject. */
    #[Column(ColumnType::IntUnsigned)]
    public int $subject_id = 0;

    /**
     * The currently-outstanding concerns, as a typed set — `#[BitmaskCaster]` transparently folds it
     * to/from the stored `SMALLINT UNSIGNED` bitmask. See {@see StockConcern} for the (core-owned,
     * closed) vocabulary. The raw-int column is what the SQL read paths aggregate/filter
     * (`BIT_OR(bits)`, `(bits & X) <> 0`); this decoded set is for PHP consumers.
     *
     * `SMALLINT UNSIGNED` gives 16 bits — comfortably beyond the realistic concern vocabulary; INT
     * would be 30 bits of permanent dead space.
     *
     * A row exists only when concerns are present; the drainer deletes the row when the last concern
     * clears, keeping the table tiny — so an empty set must never be persisted (see {@see validate()}).
     *
     * @var list<StockConcern>
     */
    #[Column(ColumnType::SmallIntUnsigned, default: 0)]
    #[Index('idx_bits')]
    #[BitmaskCaster(StockConcern::class)]
    public array $bits = [];

    /**
     * Cached unfulfillable quantity (`sum(qty_outstanding) − ctd`) for the subject when the
     * `STOCK_DEFICIT` bit is set; `0` otherwise. Denormalised here by the drainer at the moment it
     * computes the deficit bit — it already holds both operands — so read paths (the Central
     * Workbench's "Deficit: N" cell) get the magnitude without re-summing order lines per row.
     *
     * `INT UNSIGNED`: a flash-sale oversell can run well past a SMALLINT's 65 535.
     */
    #[Column(ColumnType::IntUnsigned, default: 0)]
    public int $deficit_qty = 0;

    /**
     * The deficit's minuend: committed demand for the subject — `sum(qty_outstanding)` over paid,
     * undispatched orders — as it stood when {@see $deficit_qty} was computed.
     *
     * Stored with {@see $ctd_qty} from the same computation so that `deficit_qty =
     * max(0, demand_qty − ctd_qty)` holds on every row, and a reader can show the deficit as the
     * difference it is. The same deficit arises from many pairs — `5 − 4` and `4 − 3` are both
     * `1` — and which one is the story: a line whose own demand was corrected away can still wear
     * a deficit that belongs to the rest of the store, and the operands are what say so.
     *
     * `0` in both operands means *not known*: a row cached before these columns existed, or a
     * computation that found nothing owed and so never read the balance. Readers show no equation
     * then rather than a false `0 − 0`.
     */
    #[Column(ColumnType::IntUnsigned, default: 0)]
    public int $demand_qty = 0;

    /** The deficit's subtrahend: the subject's `ctd` balance at the same instant as {@see $demand_qty}. */
    #[Column(ColumnType::IntUnsigned, default: 0)]
    public int $ctd_qty = 0;

    /** First time the current set of concerns started showing. */
    #[Column(ColumnType::DateTime, nullable: true, precision: 6)]
    public ?\DateTimeImmutable $detected_at = null;

    /** Latest drainer recompute that touched this row. */
    #[Column(ColumnType::DateTime, nullable: true, precision: 6)]
    public ?\DateTimeImmutable $updated_at = null;

    /**
     * FK to the owning subject with ON DELETE CASCADE, so a subject's removal (product deleted,
     * catalog reseed) takes its concern row with it — no orphan rows. Mirrors {@see SubjectCost}.
     */
    #[Relation(
        RelationType::ManyToOne,
        class: Subject::class,
        foreignKey: 'subject_id',
        onDelete: ForeignKeyAction::Cascade,
    )]
    public ?Subject $subject = null;

    #[\Override]
    public function beforeSave(): void
    {
        $now = new \DateTimeImmutable();
        if (null === $this->detected_at) {
            $this->detected_at = $now;
        }
        $this->updated_at = $now;
    }

    #[\Override]
    public function validate(): void
    {
        if ($this->subject_id <= 0) {
            throw new RecordValidationException(
                'SubjectStockConcern.subject_id must be a positive integer.',
                ['field' => 'subject_id', 'value' => $this->subject_id],
            );
        }
        if ([] === $this->bits) {
            // An empty-set row would mean "concern exists but it's nothing" — incoherent with the
            // schema invariant. The drainer must DELETE instead of UPSERT in that case.
            throw new RecordValidationException(
                'SubjectStockConcern.bits must be non-empty; an empty-set row violates the '
                .'"no row = no concern" invariant. Delete the row instead of saving it cleared.',
                ['field' => 'bits', 'subject_id' => $this->subject_id],
            );
        }
    }
}
