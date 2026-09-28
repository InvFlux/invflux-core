<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Domain\Procurement;

use Nandan108\Attrecord\Attribute\Column;
use Nandan108\Attrecord\Attribute\ForeignKey;
use Nandan108\Attrecord\Attribute\LockTier;
use Nandan108\Attrecord\Attribute\PrimaryKey;
use Nandan108\Attrecord\Attribute\Relation;
use Nandan108\Attrecord\Attribute\Table;
use Nandan108\Attrecord\Enum\ColumnType;
use Nandan108\Attrecord\Enum\ForeignKeyAction;
use Nandan108\Attrecord\Enum\RelationType;
use Nandan108\Attrecord\Exception\RecordValidationException;
use Nandan108\Attrecord\Record;
use Nandan108\InvFlux\Domain\Subject\Subject;

/**
 * The per-subject cost sidecar — **core/Essentials** (table `invflux_subject_cost_metadata`),
 * keyed by `subject_id` (one row per subject). Holds the seed cost basis and the
 * weighted-average cost (WAC); cost stays **off** the `invflux_subjects` table, which is
 * identity-focused.
 *
 * Cost basis + WAC are Essentials (the valuation/margin bootstrap). The FIFO-Costing add-on
 * **changes no schema**: a cost layer is a batch (child) subject, so its per-layer
 * acquisition cost is just another row in this same sidecar, keyed by that subject's id —
 * `Subject.kind` (`unit` vs `batch`) distinguishes a WAC row from a layer-cost row.
 *
 * @api
 *
 * @psalm-suppress PossiblyUnusedProperty Properties are hydrated by attrecord from row data.
 */
// The cost area is a `loc` dimension value, referenced by table name rather than by class: the
// Record declaring `invflux_dimension_values` lives in the MySQL storage package, which core does
// not depend on. RESTRICT because a location that costs are denominated in is not something a
// value drain may remove out from under them — the cost rows have to be settled first.
#[ForeignKey(
    column: 'cost_area_id',
    references: 'invflux_dimension_values',
    onDelete: ForeignKeyAction::Restrict,
    onUpdate: ForeignKeyAction::Restrict,
)]
#[Table(name: 'invflux_subject_cost_metadata')]
#[PrimaryKey(columns: ['subject_id', 'cost_area_id'])]
#[LockTier(32)]
final class SubjectCost extends Record
{
    /**
     * FK to `invflux_subjects.id`, and the key's leading member — one cost row per subject *per
     * cost area*.
     *
     * Leading because every read here starts from a subject: "what does this cost" is asked far
     * more often than "what is denominated in this area", so the key's own prefix serves the
     * common lookup and a second index would be dead weight.
     */
    #[Column(ColumnType::IntUnsigned)]
    public int $subject_id = 0;

    /**
     * The `loc` value this cost is denominated in — a cost area, in the sense a SAP plant is a
     * valuation area: the same subject may be held at different cost in different places, and the
     * storage locations *below* one share its figure rather than each carrying their own.
     *
     * Its domain is the **commercial** layer's `loc` values, which the layer definitions already
     * guarantee rather than leaving to a rule: the commercial layer selects `loc` by
     * `levels(warehouse, external)` while the physical layer selects `leaf()`, so a bin is
     * physical-only and can never be named here.
     *
     * Single-valued in practice today — every install has one warehouse, so every row carries the
     * same one — but part of the key regardless, because what the column *means* is not "which
     * warehouse happens to be the only one" but "which area this figure is denominated in", and a
     * second area must produce a second row rather than overwrite the first.
     *
     * Non-nullable with a 0 default, matching {@see $subject_id} and the NOT NULL column. The cost
     * is that an unassigned member reads as 0 rather than as absent, so attrecord's incomplete-key
     * guard cannot fire on a delete here; {@see validate()} rejects a non-positive member before
     * any write instead. 0 also cannot reach the database on an insert — it satisfies no
     * `invflux_dimension_values.id`, so the foreign key refuses it.
     *
     * Writers resolve it with {@see \Nandan108\InvFlux\Contracts\Inventory\SchemaManager::dimensionValueId()},
     * which is the port that did not exist while this column was optional.
     */
    #[Column(ColumnType::IntUnsigned)]
    public int $cost_area_id = 0;

    /**
     * Which class of thing this subject is, for the purpose of the accounts its stock belongs in —
     * raw material, finished good, trading good, and whatever else a merchant's chart of accounts
     * distinguishes.
     *
     * **It is the item axis, and it exists because the reason axis cannot answer for it.**
     * `AdjustmentReason::$gl_class` classifies *why* stock moved, and so decides which expense
     * account a write-off hits; this classifies *what moved*, and so decides which inventory asset
     * account it leaves. Account determination needs both: scrapping a raw material and scrapping a
     * finished good share a movement type **and** a reason, yet must credit different accounts.
     *
     * **InvFlux classifies; it never posts.** No account number appears here and none ever should —
     * mapping a class to an account is the ledger connector's job and the merchant's chart of
     * accounts. What this column buys is that the connector *can* map: without it the feed carries
     * no item axis and the connector has nothing to distinguish those two write-offs by either.
     *
     * A free code rather than an enum, unlike `gl_class`: the set of GL *treatments* is closed and
     * core-fixed, while valuation classes are the merchant's, vary with their chart of accounts,
     * and are configured per install in every ERP that has them. A catalog table would validate
     * them, and stays available — adding one later means backfilling a foreign key from values that
     * are already free-form strings, which is a gentler move than the enum-to-catalog migration
     * {@see \Nandan108\InvFlux\Domain\Stock\AdjustmentReason} warns is only cheap at greenfield.
     *
     * Here rather than on `Subject` because valuation is where it belongs: SAP carries it per
     * material × valuation *area*, not on the material master, which is the grain this table now
     * has. Null at Essentials, where nothing reads it.
     */
    #[Column(ColumnType::VarChar, length: 32, nullable: true)]
    public ?string $valuation_class = null;

    /** Merchant-set / seeded cost basis (the Essentials valuation bootstrap). */
    #[Column(ColumnType::Decimal, precision: 10, scale: 4, nullable: true)]
    public ?string $seed_cost = null;

    /** Weighted-average cost, recomputed on goods receipt (Essentials WAC). */
    #[Column(ColumnType::Decimal, precision: 10, scale: 4, nullable: true)]
    public ?string $weighted_avg_cost = null;

    /** ISO-4217 store-base currency for the cost figures. */
    #[Column(ColumnType::VarChar, length: 3, nullable: true)]
    public ?string $cost_currency = null;

    /** Actor that last wrote the cost (workbench bulk-edit / WAC projection). */
    #[Column(ColumnType::IntUnsigned, nullable: true)]
    public ?int $updated_by = null;

    /**
     * When the cost was last written — set by the writer (workbench bulk-edit /
     * WAC projection). Nullable rather than auto-stamped: this Record's PK is the
     * provided `subject_id`, and attrecord emits every non-generated column on the
     * insert, so neither a beforeSave mutation nor a DB CURRENT_TIMESTAMP default is
     * reliable here — the writer sets it explicitly.
     */
    #[Column(ColumnType::DateTime, precision: 6, nullable: true)]
    public ?\DateTimeImmutable $updated_at = null;

    #[Relation(
        RelationType::ManyToOne,
        class: Subject::class,
        foreignKey: 'subject_id',
        onDelete: ForeignKeyAction::Cascade,
    )]
    public ?Subject $subject = null;

    #[\Override]
    public function validate(): void
    {
        if ($this->subject_id <= 0) {
            throw new RecordValidationException(
                'SubjectCost.subject_id must be a positive integer.',
                ['field' => 'subject_id', 'value' => $this->subject_id],
            );
        }
        // Both key members, because both are typed `int` with a 0 default rather than nullable —
        // so "never set" and "set to zero" look identical to attrecord's incomplete-key guard, and
        // this is what stands in for it. A cost figure that names no area is not a cheaper row: it
        // is a figure nobody can say what it values.
        if ($this->cost_area_id <= 0) {
            throw new RecordValidationException(
                'SubjectCost.cost_area_id must be a positive integer — a cost figure has to name the area it is denominated in.',
                ['field' => 'cost_area_id', 'value' => $this->cost_area_id],
            );
        }
    }
}
