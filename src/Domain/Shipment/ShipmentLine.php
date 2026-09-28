<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Domain\Shipment;

use Nandan108\Attrecord\AppendOnly;
use Nandan108\Attrecord\Attribute\Column;
use Nandan108\Attrecord\Attribute\ForeignKey;
use Nandan108\Attrecord\Attribute\Index;
use Nandan108\Attrecord\Attribute\LockTier;
use Nandan108\Attrecord\Attribute\PrimaryKey;
use Nandan108\Attrecord\Attribute\Relation;
use Nandan108\Attrecord\Attribute\Table;
use Nandan108\Attrecord\Caster\EnumCaster;
use Nandan108\Attrecord\Enum\ColumnType;
use Nandan108\Attrecord\Enum\ForeignKeyAction;
use Nandan108\Attrecord\Enum\RelationType;
use Nandan108\Attrecord\Exception\RecordValidationException;
use Nandan108\Attrecord\Record;
use Nandan108\InvFlux\Domain\Order\OrderLine;

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
#[PrimaryKey(columns: ['shipment_id', 'order_line_id'])]
// Declared rather than left to the database, which would create one of its own to carry the
// foreign key below and name it after the constraint. Same columns either way; declaring it means
// the order of them is ours and the Record says what exists. It doubles as the lookup for "every
// shipment line against this order", since `order_id` leads it — an index on `order_id` alone
// would be that same prefix a second time.
#[Index('idx_order_line', columns: ['order_id', 'order_line_id'])]
// The line reference is the order line's whole key, which is what makes "this shipment line
// belongs to a line of some *other* order" unrepresentable rather than merely absent
#[ForeignKey(
    column: ['order_id', 'order_line_id'],
    references: OrderLine::class,
    onDelete: ForeignKeyAction::Restrict,
)]
// And this one closes the other half of the diamond. `shipment_id` and `order_id` both lead
// somewhere that knows the order, and nothing made the two agree; pairing them against the
// shipment's own (id, order_id) does. That is what the redundant unique key on
// {@see Shipment} is for — it exists to be the target of this constraint and nothing else.
#[ForeignKey(
    column: ['shipment_id', 'order_id'],
    references: 'invflux_shipments',
    referencesColumn: ['id', 'order_id'],
    onDelete: ForeignKeyAction::Restrict,
)]
#[LockTier(25)]
final class ShipmentLine extends Record implements AppendOnly
{
    /**
     * 16-byte binary UUIDv7 FK to invflux_shipments.id, and the key's leading member. Null at
     * construction — {@see ShipmentRepository::persist()} re-points each line at the saved
     * shipment's id before insert (the NOT NULL column enforces it at write).
     */
    #[Column(ColumnType::Binary, length: 16)]
    public ?string $shipment_id = null;

    /**
     * Which order line this ships — the *order's* numbering, not a numbering of its own.
     *
     * Named `order_line_id` rather than `line_id` because this table has its own line concept, and
     * a bare `line_id` on a row that *is* a line reads as its own id. `OrderCorrection.line_id`
     * keeps the shorter name for the mirror-image reason: a correction has no lines, so there is
     * nothing for it to be confused with.
     *
     * Together with {@see $shipment_id} this is the key: a shipment ships a given order line at
     * most once, which held on every row of the development store and is now enforced rather than
     * observed. A reversal or a resend is a *different shipment*, so the exceptional flows collide
     * with nothing here.
     */
    #[Column(ColumnType::SmallIntUnsigned)]
    public int $order_line_id = 0;

    /**
     * The order the line belongs to, carried so the reference above can be a real constraint —
     * a foreign key names columns on this row and cannot reach through `shipment_id` to find it.
     *
     * Denormalised, like {@see $subject_id} beside it, and kept honest by the second constraint
     * above rather than by the writer remembering.
     */
    #[Column(ColumnType::Binary, length: 16)]
    public ?string $order_id = null;

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

    // Both parents are referenced by the class-level #[ForeignKey] declarations above, which are
    // multi-column and so cannot be expressed on a #[Relation]. These stay for hydration only,
    // with `emitFk: false` — declaring the constraint twice would emit it twice.
    //
    // RESTRICT rather than CASCADE on both, because this Record is {@see AppendOnly}: its rows may
    // not be deleted at all, and the existence of one is itself the assertion that a shipment
    // moved these units. A cascade would delete them regardless — it executes inside the database,
    // below the runtime guard attrecord raises on delete() — so declaring the row undeletable and
    // then pointing a CASCADE at it says two different things and the schema wins silently. It is
    // also what the rest of the append-only set already does: the ledger, the stock-management
    // events and the document-party decisions all refuse a parent delete rather than follow it.
    #[Relation(
        RelationType::ManyToOne,
        class: Shipment::class,
        foreignKey: 'shipment_id',
        emitFk: false,
    )]
    public ?Shipment $shipment = null;

    #[Relation(
        RelationType::ManyToOne,
        class: OrderLine::class,
        foreignKey: 'order_line_id',
        emitFk: false,
    )]
    public ?OrderLine $orderLine = null;

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
        if (null === $this->order_id || 16 !== \strlen($this->order_id)) {
            throw new RecordValidationException(
                'ShipmentLine.order_id must be a 16-byte binary UUIDv7 referencing invflux_orders.id.',
                ['field' => 'order_id'],
            );
        }
        // A typed int with a 0 default, so there is no null for the incomplete-key guard to catch
        // and this stands in for it — as on {@see OrderLine::$line_id}, which it mirrors.
        if ($this->order_line_id <= 0) {
            throw new RecordValidationException(
                'ShipmentLine.order_line_id must be a positive order-line number.',
                ['field' => 'order_line_id', 'value' => $this->order_line_id],
            );
        }
        // $cost_basis is enum-typed (EnumCaster) — the type system guarantees a valid CostBasis (or null).
    }
}
