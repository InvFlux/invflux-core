<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Domain\Subject;

use Nandan108\Attrecord\Attribute\Check;
use Nandan108\Attrecord\Attribute\Column;
use Nandan108\Attrecord\Attribute\ForeignKey;
use Nandan108\Attrecord\Attribute\Index;
use Nandan108\Attrecord\Attribute\Relation;
use Nandan108\Attrecord\Attribute\Table;
use Nandan108\Attrecord\Caster\EnumCaster;
use Nandan108\Attrecord\Enum\ColumnType;
use Nandan108\Attrecord\Enum\ForeignKeyAction;
use Nandan108\Attrecord\Enum\RelationType;
use Nandan108\Attrecord\Record;

/**
 * One inventory subject — the stable identity anchor referenced by the ledger,
 * inventory state, and external-identifier assignments.
 *
 * `kind` controls the subject's role in the hierarchy:
 * - `Aggregate`: grouping node (no direct inventory); children must be `Unit` or `Kit`.
 * - `Unit`: commercial inventory unit; may be root (simple product) or a child
 *   of an `Aggregate` (variation); may have `Batch` children.
 * - `Kit`: kit-on-sale parent; holds no slots, components via the BOM table, availability
 *   derived. Same FK shape as `Unit` (root simple product or `Aggregate` child / variation);
 *   never a parent.
 * - `Batch`: physical leaf; parent must be a `Unit`.
 *
 * `tracking` (see {@see SubjectTracking}) decides which layers a `Unit` holds: untracked it
 * keeps both the commercial and the physical layer; lot- or serial-tracked it keeps the
 * commercial layer and delegates the physical one to its `Batch` children.
 *
 * `product_id` and `variant_id` are denormalized FK shortcuts for ledger /
 * projection queries. The implementation (single-item `registerSubject()` or
 * bulk `resolveOrCreateManyByIdentifier()`) fills them in:
 *
 * | Kind                          | product_id        | variant_id        |
 * |-------------------------------|-------------------|-------------------|
 * | root Unit/Kit (simple)        | self              | self              |
 * | root Aggregate                | self              | null              |
 * | Unit/Kit child of Aggregate   | parent.product_id | self              |
 * | Batch child of Unit           | parent.product_id | parent.variant_id |
 *
 * @api
 *
 * @psalm-suppress PossiblyUnusedProperty Properties are hydrated by attrecord from row data.
 */
#[Table(name: 'invflux_subjects')]
#[Index('idx_product_kind', columns: ['product_id', 'kind'])]
#[Index('idx_variant_kind', columns: ['variant_id', 'kind'])]
// The single-row half of the legality rules stated on SubjectKind, mirrored where a write that
// never passes through this layer still meets them: WP-CLI, a neighbouring plugin, a SQL prompt.
// SubjectKind::allowsTracking() and ::canBeChildOf() remain the authority — these are defence in
// depth, and deliberately only the part a single row can answer. Which *kind* a parent is takes a
// second row to know, so it stays in the application entirely.
#[Check('tracking_unit_only', "kind = 'unit' OR tracking = 'none'")]
#[Check('batch_has_parent', "kind <> 'batch' OR parent_id IS NOT NULL")]
#[Check('aggregate_is_root', "kind <> 'aggregate' OR parent_id IS NULL")]
// Costing at batch grain needs somewhere to put a layer, and that somewhere is a `Batch` child,
// which only a tracked subject has. Same defence-in-depth as the rules above, and answerable from
// one row because both columns are on it.
#[Check('cost_grain_needs_tracking', "cost_grain = 'unit' OR tracking <> 'none'")]
// The document line a `Batch` subject was born from. Referenced by table name rather than by class:
// `invflux_ref_types` has no Record — it is raw DDL reachable only as a private constant in the
// MySQL store — so a class reference has nothing to resolve. RESTRICT because a ref type some
// subject still cites is not an orphan.
#[ForeignKey(
    column: 'source_ref_type_id',
    references: 'invflux_ref_types',
    onDelete: ForeignKeyAction::Restrict,
    onUpdate: ForeignKeyAction::Restrict,
)]
final class Subject extends Record
{
    #[Column(ColumnType::IntUnsigned, autoIncrement: true)]
    public ?int $id = null;

    #[Column(ColumnType::IntUnsigned, nullable: true)]
    #[Index('idx_parent')]
    public ?int $parent_id = null;

    /** Role in the hierarchy (see {@see SubjectKind}). ENUM value list derived from the caster's enum. */
    #[Column(ColumnType::Enum, default: SubjectKind::Unit)]
    #[EnumCaster(SubjectKind::class)]
    public SubjectKind $kind = SubjectKind::Unit;

    /**
     * How this subject's stock is physically identified at capture (see {@see SubjectTracking}) —
     * and therefore whether it holds its own physical slots or delegates them to `Batch` children.
     * Legal as anything but `None` on `Unit` alone ({@see SubjectKind::allowsTracking()}).
     *
     * Set by the add-on that captures the identity (lot / serial), never as a side effect of a
     * subject coming into existence; a base install leaves every row at `None`. Substrate only —
     * **unused at Essentials v1.0**, and added now because a column is cheapest to introduce
     * before there is data.
     */
    #[Column(ColumnType::Enum, default: SubjectTracking::None)]
    #[EnumCaster(SubjectTracking::class)]
    public SubjectTracking $tracking = SubjectTracking::None;

    /**
     * Which subject a cost layer hangs off (see {@see CostGrain}) — the unit itself, or its
     * `Batch` children. `Batch` is legal only where {@see $tracking} is anything but `None`, since
     * an untracked subject has no children to hold a layer.
     *
     * Orthogonal to `tracking` despite that dependency: lot identity is a physical fact and cost
     * grain a financial one, so a lot-tracked subject on a single rolling average is an ordinary
     * arrangement rather than a contradiction.
     *
     * Substrate only — **unused at Essentials v1.0**, where weighted average cost makes the grain
     * unobservable and every row is `Unit`. Added now because a column is cheapest to introduce
     * before there is data.
     */
    #[Column(ColumnType::Enum, default: CostGrain::Unit)]
    #[EnumCaster(CostGrain::class)]
    public CostGrain $cost_grain = CostGrain::Unit;

    /**
     * The document line a `Batch` subject was born from — which receipt line, or which build
     * order, brought this lot into existence. Null on every other kind, and on batches predating
     * the anchor.
     *
     * Stored as the (ref type, ref id) pair the ledger already uses for movement provenance rather
     * than as a typed FK per document kind, so a new document type that can mint a batch needs a
     * `ref_types` row and no schema change here.
     */
    #[Column(ColumnType::SmallIntUnsigned, nullable: true)]
    public ?int $source_ref_type_id = null;

    /** The referenced row's id, read against {@see $source_ref_type_id}. */
    #[Column(ColumnType::BigIntUnsigned, nullable: true)]
    public ?int $source_ref_id = null;

    #[Column(ColumnType::IntUnsigned, nullable: true)]
    public ?int $product_id = null;

    #[Column(ColumnType::IntUnsigned, nullable: true)]
    public ?int $variant_id = null;

    /**
     * Low stock threshold, mirroring the host's own field where the host has one.
     *
     * Host-owned wherever a host owns it: an adapter whose platform carries this concept (for
     * WooCommerce, `_low_stock_amount`) mirrors that value here rather than keeping a competing
     * one, and the platform stays authoritative for it. So this column carries no provenance flag —
     * there is one source of truth, and a second opinion about who set it would only be a way to
     * disagree with it.
     *
     * A *derived* reorder point belongs in its own column, never written back into this one: the
     * merchant's threshold is an input to that calculation (the fallback when there is too little
     * data to compute one), and collapsing the two would overwrite the input with the output.
     *
     * `renamedFrom` makes convergence emit a data-preserving `RENAME COLUMN` rather than the
     * drop-and-add a differ otherwise plans when a column appears under a new name. It is a
     * migration instruction rather than a permanent record, so it may be removed once every
     * database that could hold the old name has converged.
     */
    #[Column(ColumnType::SmallIntUnsigned, nullable: true, renamedFrom: 'reorder_threshold')]
    public ?int $low_stock_amount = null;

    /**
     * The level a restock order fills up to — the other end of the cycle {@see $low_stock_amount}
     * starts. The threshold says *when* to order, this says *how much to end up with*, and a
     * suggestion is the gap between this and what is already here or on its way.
     *
     * **Null inherits** a multiple of the threshold, which is what the two ends of the cycle are
     * related by when a merchant has not said otherwise. Stored separately rather than always
     * derived, because the ratio is one setting for the whole store: without this column a merchant
     * can pick any fill level they like by choosing the threshold, but cannot then choose
     * independently when they are warned — wanting a lot on the shelf forces an early warning, and
     * the two are not the same question.
     *
     * **Ours, not the host's.** Unlike the threshold, which mirrors WooCommerce's `_low_stock_amount`
     * and leaves the platform authoritative, no host field means this, so nothing is being shadowed.
     *
     * Not to be confused with a supplier's minimum order quantity, which lives on the supplier
     * catalogue line and is a floor on *an order*; this is a floor on *the resulting position*, and
     * it is the difference that decides whether stock already inbound is subtracted.
     */
    #[Column(ColumnType::SmallIntUnsigned, nullable: true)]
    public ?int $reorder_target = null;

    /**
     * Whether **InvFlux governs** this subject's stock — i.e. InvFlux is the single authority that
     * owns the quantity, projecting it into WooCommerce's `_stock`, intercepting foreign writes,
     * and reconciling. This is **distinct from** WooCommerce's own `_manage_stock` bit: a product
     * can have `_manage_stock = true` yet be *un*-governed by InvFlux (WC-native or another plugin
     * counts it), in which case InvFlux stays hands-off and only reads WC's native value. Governed
     * always implies `_manage_stock = true` (you can't govern an unmanaged product); the reverse no
     * longer holds. `ivfx_` disambiguates *by whom* — from InvFlux's point of view a subject is
     * either InvFlux-governed or not-InvFlux, and it needn't model whoever "not-InvFlux" is.
     *
     * Defaults to **false** (opt-in): a subject materialised without an explicit governance
     * decision — lazily, e.g. from order projection or a first stock read — stays out of
     * InvFlux's hands and behaves as vanilla WooCommerce until deliberately adopted. Governance
     * is only ever turned on by an explicit decision (product tab, workbench, the new-product
     * policy, or a bulk adopt), never as a side effect of a subject coming into existence.
     */
    #[Column(ColumnType::Bool, default: false)]
    public bool $ivfx_governed = false;

    /**
     * Per-subject fixed-scale decimal precision for stock quantities — an OVERRIDE of the global
     * `config_state.quantity_scale`. Null = inherit the global scale. Effective scale `0` ⟹ **discrete**
     * (whole units; a fraction is unrepresentable, so integrality is enforced for free); `≥ 1` ⟹
     * **continuous** (fractional quantities — weight / length / volume). Substrate only: **unused at Essentials
     * v1.0** — the UoM-conversion subsystem and kits/assemblies are its consumers, and WooCommerce has no
     * native fractional cart. Added now because a grain column is cheapest to introduce before there is
     * data.
     */
    #[Column(ColumnType::TinyIntUnsigned, nullable: true)]
    public ?int $scale = null;

    #[Column(ColumnType::DateTime, precision: 6)]
    public ?\DateTimeImmutable $created_at = null;

    // Self-referencing FKs — declared via Relations so attrecord emits the FK
    // constraints. The navigation properties are not used in application code
    // (the denormalized FK columns are read directly); they exist purely as
    // attrecord's syntactic anchor for the constraint declaration.

    #[Relation(
        RelationType::ManyToOne,
        class: self::class,
        foreignKey: 'parent_id',
        onDelete: ForeignKeyAction::Cascade,
    )]
    public ?self $parent = null;

    #[Relation(
        RelationType::ManyToOne,
        class: self::class,
        foreignKey: 'product_id',
        onDelete: ForeignKeyAction::Cascade,
    )]
    public ?self $product = null;

    #[Relation(
        RelationType::ManyToOne,
        class: self::class,
        foreignKey: 'variant_id',
        onDelete: ForeignKeyAction::Cascade,
    )]
    public ?self $variant = null;

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
        // $kind is enum-typed (EnumCaster) — the type system guarantees a valid value, so no
        // runtime membership check is needed.
    }
}
