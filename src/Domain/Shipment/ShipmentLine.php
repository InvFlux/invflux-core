<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Domain\Shipment;

use Nandan108\Attrecord\AppendOnly;
use Nandan108\Attrecord\Attribute\Column;
use Nandan108\Attrecord\Attribute\Index;
use Nandan108\Attrecord\Attribute\LockTier;
use Nandan108\Attrecord\Attribute\Relation;
use Nandan108\Attrecord\Attribute\Table;
use Nandan108\Attrecord\Caster\EnumCaster;
use Nandan108\Attrecord\Enum\ColumnType;
use Nandan108\Attrecord\Enum\ForeignKeyAction;
use Nandan108\Attrecord\Enum\RelationType;
use Nandan108\Attrecord\Exception\RecordValidationException;
use Nandan108\Attrecord\Record;
use Nandan108\InvFlux\Domain\Order\OrderLine;
use Nandan108\InvFlux\Identity\RecordIdentity;

/**
 * One order line on one {@see Shipment}: the quantity that left, and the
 * **authoritative at-fulfilment unit cost** — the real COGS basis.
 *
 * `unit_cost` is stamped at the **WAC of the dispatch instant** (the same
 * instant the inventory decrement posts, in the same transaction, so the two
 * always agree —). It is **immutable** once written.
 * `cost_basis` records which valuation method produced it (`wac` at Essentials; Pro
 * FIFO writes layer-consumed cost into this same surface with `fifo`, no schema
 * change). **`null` unit_cost = uncosted** (no WAC and no seed cost for
 * the subject) — surface it as such, never as zero (zero fakes a 100% margin).
 *
 * **Period COGS** = `Σ over shipment lines (qty_shipped × unit_cost)`, net of
 * return-correction reversals. `subject_id` is denormalised (mirrors
 * {@see OrderLine}) so cost roll-ups join per subject without walking order
 * lines.
 *
 * **Write-once ({@see AppendOnly}).** A shipment line records what physically left in one
 * dispatch, with its cost stamped at that instant; it is never updated or deleted (a later
 * return posts a *correction*, not a mutation). Persisted via `RecordSet::insertAll()`.
 *
 * @api
 *
 * @psalm-suppress PossiblyUnusedProperty Properties are hydrated by attrecord from row data.
 */
#[Table(name: 'invflux_shipment_lines')]
#[LockTier(25)]
final class ShipmentLine extends Record implements AppendOnly
{
    /** 16-byte binary UUIDv7 — minted on save via RecordIdentity. */
    #[Column(ColumnType::Binary, length: 16)]
    public ?string $id = null;

    /**
     * 16-byte binary UUIDv7 FK to invflux_shipments.id. Null at construction —
     * {@see ShipmentRepository::persist()} re-points each line at the saved
     * shipment's id before insert (the NOT NULL column enforces it at write).
     */
    #[Column(ColumnType::Binary, length: 16)]
    #[Index('idx_shipment')]
    public ?string $shipment_id = null;

    /** 16-byte binary UUIDv7 FK to invflux_order_lines.id. */
    #[Column(ColumnType::Binary, length: 16)]
    #[Index('idx_order_line')]
    public ?string $line_id = null;

    /** Denormalised subject (FK reachable via the order line) for per-subject COGS roll-ups. */
    #[Column(ColumnType::IntUnsigned)]
    #[Index('idx_subject')]
    public int $subject_id = 0;

    #[Column(ColumnType::SmallIntUnsigned, default: 0)]
    public int $qty_shipped = 0;

    /** Authoritative COGS unit cost — WAC at dispatch instant. Null = uncosted. Immutable. */
    #[Column(ColumnType::Decimal, precision: 10, scale: 4, nullable: true)]
    public ?string $unit_cost = null;

    /** Valuation method that produced unit_cost: `wac` (Essentials) or `fifo` (Pro). Null when uncosted. ENUM value list derived from the caster's enum. */
    #[Column(ColumnType::Enum, nullable: true)]
    #[EnumCaster(CostBasis::class)]
    public ?CostBasis $cost_basis = null;

    /** ISO-4217 store-base currency of unit_cost (cost is store-base). Null when uncosted. */
    #[Column(ColumnType::Char, length: 3, nullable: true)]
    public ?string $cost_currency = null;

    #[Relation(
        RelationType::ManyToOne,
        class: Shipment::class,
        foreignKey: 'shipment_id',
        onDelete: ForeignKeyAction::Cascade,
    )]
    public ?Shipment $shipment = null;

    #[Relation(
        RelationType::ManyToOne,
        class: OrderLine::class,
        foreignKey: 'line_id',
        onDelete: ForeignKeyAction::Cascade,
    )]
    public ?OrderLine $orderLine = null;

    #[\Override]
    public function beforeSave(): void
    {
        if (null === $this->id) {
            $this->id = RecordIdentity::mintId();
        }
    }

    #[\Override]
    public function validate(): void
    {
        // shipment_id is assigned by the repository at persist time, so null is
        // allowed at construction; when set it must be a 16-byte binary UUIDv7.
        if (null !== $this->shipment_id && 16 !== \strlen($this->shipment_id)) {
            throw new RecordValidationException(
                'ShipmentLine.shipment_id must be a 16-byte binary UUIDv7 referencing invflux_shipments.id.',
                ['field' => 'shipment_id'],
            );
        }
        if (null === $this->line_id || 16 !== \strlen($this->line_id)) {
            throw new RecordValidationException(
                'ShipmentLine.line_id must be a 16-byte binary UUIDv7 referencing invflux_order_lines.id.',
                ['field' => 'line_id'],
            );
        }
        // $cost_basis is enum-typed (EnumCaster) — the type system guarantees a valid CostBasis (or null).
    }
}
