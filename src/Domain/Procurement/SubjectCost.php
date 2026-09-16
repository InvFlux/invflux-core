<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Domain\Procurement;

use Nandan108\Attrecord\Attribute\Column;
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
#[Table(name: 'invflux_subject_cost_metadata', primaryKey: 'subject_id')]
#[LockTier(32)]
final class SubjectCost extends Record
{
    /** PK + FK to `invflux_subjects.id` — one cost row per subject. */
    #[Column(ColumnType::IntUnsigned)]
    public int $subject_id = 0;

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
    }
}
