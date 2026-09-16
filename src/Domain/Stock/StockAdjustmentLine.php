<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Domain\Stock;

use Nandan108\Attrecord\Attribute\Column;
use Nandan108\Attrecord\Attribute\Index;
use Nandan108\Attrecord\Attribute\Relation;
use Nandan108\Attrecord\Attribute\Table;
use Nandan108\Attrecord\Enum\ColumnType;
use Nandan108\Attrecord\Enum\ForeignKeyAction;
use Nandan108\Attrecord\Enum\RelationType;
use Nandan108\Attrecord\Record;

/**
 * One per-subject line of a {@see StockAdjustment} document — the adjustment analog of
 * `ReceiptLine` / `ShipmentLine` (both of which carry a cost). A `StockAdjustment` is a
 * *multi-subject header*, so the per-subject signed quantity **and its valuation** live here,
 * not on the header. One line per applied subject; the ledger movement for that subject joins
 * back via (`subject_id`, the `stock_adjustment` ref) → this line's `unit_cost`.
 *
 * `unit_cost` is an **auto-snapshot of `weighted_avg_cost ?? seed_cost` at apply — never a user
 * input**, grounded by its own `cost_currency`. It does not move WAC (only receipts do) and does not
 * change the on-hand valuation (`WAC × on-hand`); it exists purely to **value that movement** on the
 * accounting-export trail. `null` is a graceful "uncosted movement" (a never-costed-never-seeded
 * subject). This is the one capture-time-only piece — un-backfillable — see docs/arch-erp-parity.
 *
 * @api
 *
 * @psalm-suppress PossiblyUnusedProperty Properties are hydrated by attrecord from row data.
 * @psalm-suppress UnusedClass Persisted via attrecord by the adapter + table emitted by storage's
 *                             install — both outside core's analysis scope.
 */
#[Table(name: 'invflux_stock_adjustment_lines')]
final class StockAdjustmentLine extends Record
{
    #[Column(ColumnType::IntUnsigned, autoIncrement: true)]
    public ?int $id = null;

    /** FK to the owning {@see StockAdjustment} header (its 16-byte UUIDv7 id). */
    #[Column(ColumnType::Binary, length: 16)]
    #[Index('idx_adjustment')]
    public ?string $adjustment_id = null;

    #[Column(ColumnType::IntUnsigned)]
    #[Index('idx_subject')]
    public int $subject_id = 0;

    /** Signed applied quantity: negative = write-off (stock left), positive = write-in (stock appeared). */
    #[Column(ColumnType::Int)]
    public int $delta = 0;

    /**
     * Cost snapshot of this movement (`weighted_avg_cost ?? seed_cost` at apply).
     * Auto-captured, never user-entered; `null` = uncosted movement (no basis existed). Does not move
     * WAC or the on-hand valuation — it only values this movement for the export trail.
     */
    #[Column(ColumnType::Decimal, precision: 10, scale: 4, nullable: true)]
    public ?string $unit_cost = null;

    /**
     * ISO-4217 currency `unit_cost` is denominated in — the operating base at apply time, taken from
     * the cost basis it snapshots rather than re-derived at read time.
     *
     * A frozen line is never converted when the store base changes, so without this the amount would
     * silently be reinterpreted in the new base by every later reader. `null` alongside a non-null
     * `unit_cost` means the line predates grounding; such an amount is denominated in whatever base
     * was anchored then, and a base-currency conversion grounds it before converting the live sidecar.
     */
    #[Column(ColumnType::VarChar, length: 3, nullable: true)]
    public ?string $cost_currency = null;

    #[Column(ColumnType::DateTime, precision: 6)]
    public ?\DateTimeImmutable $created_at = null;

    #[Relation(
        RelationType::ManyToOne,
        class: StockAdjustment::class,
        foreignKey: 'adjustment_id',
        onDelete: ForeignKeyAction::Cascade,
    )]
    public ?StockAdjustment $adjustment = null;

    #[\Override]
    public function beforeSave(): void
    {
        if (null === $this->created_at) {
            $this->created_at = new \DateTimeImmutable();
        }
    }
}
