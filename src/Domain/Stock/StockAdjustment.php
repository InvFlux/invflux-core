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
use Nandan108\InvFlux\Identity\RecordIdentity;
use Nandan108\InvFlux\Identity\SurfaceRecord;

/**
 * Audit header for one stock-adjustment document — a coherent group of slot movements applied
 * together, linked from the inventory ledger via the `stock_adjustment` ref. This is the single,
 * tier-agnostic model behind every adjustment workflow: the Essentials **on-hand correction**, the
 * plugin-intercept reconciliation, bulk imports, and (later) the commit of a Pro **stock-take** or
 * **cycle-count** all produce one of these. Richer workflows live in their own session aggregates
 * and *emit* a StockAdjustment on completion — they don't extend this table.
 *
 * Discriminators:
 * - `origin` — the workflow that produced it (`on_hand_correction`, `plugin_intercept`,
 *   `bulk_import`, …). A free string, not a core enum, so adapters contribute their own origins
 *   without core knowing them (same spirit as movement-type / ref-type registries).
 * - `cause` — the *observed nature* of the adjustment (`recount` | `damage`), nullable when
 *   unspecified. Deliberately cause-agnostic: a floor worker asserts what they see (a count short,
 *   or visibly damaged units), not *why* (theft vs loss is an investigation outcome, recorded
 *   elsewhere, not at entry).
 *
 * @psalm-suppress PossiblyUnusedProperty Properties are hydrated by attrecord from row data.
 * @psalm-suppress UnusedClass Persisted via attrecord by the adapter + table emitted by storage's
 *                             createBaseTables — both outside core's analysis scope.
 */
#[Table(name: 'invflux_stock_adjustments')]
final class StockAdjustment extends Record
{
    /**
     * Accepted `discovery_context` values — *how* the correction was noticed. Orthogonal to the
     * reason: "missing during a stock-take" carries different operational weight than "missing during
     * a one-off observation.".
     */
    public const DISCOVERY_CONTEXTS = ['one_off', 'stock_take', 'cycle_count', 'other'];

    /** 16-byte binary UUIDv7 — minted on save via RecordIdentity. */
    #[Column(ColumnType::Binary, length: 16)]
    public ?string $id = null;

    /** Workflow that produced the adjustment (free string / registry-style; e.g. `on_hand_correction`). */
    #[Column(ColumnType::VarChar, length: 64)]
    public string $origin = '';

    #[Column(ColumnType::SmallIntUnsigned, nullable: true)]
    #[Index('idx_surface')]
    public ?int $surface_id = null;

    #[Column(ColumnType::BigIntUnsigned)]
    public int $operator_id = 0;

    #[Column(ColumnType::Text, nullable: true)]
    public ?string $reason = null;

    /**
     * The adjustment **reason** — FK into the configurable {@see AdjustmentReason} catalog (*what* the
     * change reflects). Sign-validity (a negative reason for a negative delta) is enforced at the
     * application layer from the reason's `sign`, and the reason's `gl_class` is what values this
     * movement for the accounting export. Null = unspecified (a plugin-reconcile / seeding row).
     * See arch-erp-parity §8.2.
     */
    #[Column(ColumnType::SmallIntUnsigned, nullable: true)]
    #[Index('idx_reason')]
    public ?int $reason_id = null;

    /**
     * How the correction was noticed: `one_off` / `stock_take` / `cycle_count` / `other`.
     * Captured separately from disposition because the two axes are operationally orthogonal
     * (audit-found discoveries weight into shrink statistics differently than incidental ones).
     */
    #[Column(ColumnType::Enum, enumValues: self::DISCOVERY_CONTEXTS, nullable: true)]
    public ?string $discovery_context = null;

    #[Column(ColumnType::VarChar, length: 255, nullable: true)]
    public ?string $file_name = null;

    #[Column(ColumnType::IntUnsigned, nullable: true)]
    public ?int $row_count = null;

    #[Column(ColumnType::DateTime)]
    public ?\DateTimeImmutable $created_at = null;

    /**
     * FK to the originating surface, nulled (not cascaded) when the surface is removed — the audit
     * row outlives the UI surface that produced it.
     */
    #[Relation(
        RelationType::ManyToOne,
        class: SurfaceRecord::class,
        foreignKey: 'surface_id',
        onDelete: ForeignKeyAction::SetNull,
    )]
    public ?SurfaceRecord $surface = null;

    /**
     * The catalog reason this adjustment was attributed to (null when unspecified) — distinct from the
     * free-text `$reason` note above (structured code + optional free-text detail). `Restrict` — a
     * reason in use can't be hard-deleted; retire it via `AdjustmentReason::$active` instead.
     */
    #[Relation(
        RelationType::ManyToOne,
        class: AdjustmentReason::class,
        foreignKey: 'reason_id',
        onDelete: ForeignKeyAction::Restrict,
    )]
    public ?AdjustmentReason $adjustmentReason = null;

    #[\Override]
    public function beforeSave(): void
    {
        if (null === $this->id) {
            $this->id = RecordIdentity::mintId();
        }
        if (null === $this->created_at) {
            $this->created_at = new \DateTimeImmutable();
        }
    }
}
