<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Domain\Subject;

use Nandan108\Attrecord\Attribute\Column;
use Nandan108\Attrecord\Attribute\Index;
use Nandan108\Attrecord\Attribute\Relation;
use Nandan108\Attrecord\Attribute\Table;
use Nandan108\Attrecord\Attribute\UniqueKey;
use Nandan108\Attrecord\Enum\ColumnType;
use Nandan108\Attrecord\Enum\ForeignKeyAction;
use Nandan108\Attrecord\Enum\GeneratedColumnMode;
use Nandan108\Attrecord\Enum\RelationType;
use Nandan108\Attrecord\Exception\RecordValidationException;
use Nandan108\Attrecord\Record;
use Nandan108\InvFlux\Identity\ActorRecord;
use Nandan108\InvFlux\Identity\IdentifierTypeRecord;
use Nandan108\InvFlux\Identity\SystemRecord;

/**
 * One row in invflux_subject_identifiers — a time-bounded assignment of an
 * external identifier (e.g. WooCommerce post_id, SKU, GTIN) to an InvFlux
 * {@see Subject}.
 *
 * Active vs. historical rows are distinguished by the `valid_to` column:
 * active assignments use the sentinel
 * {@see IdentifierValidity::OPEN_ENDED}
 * (9999-12-31 23:59:59.999999); historical rows carry a real timestamp.
 *
 * `scope_actor_key` is a STORED generated column (`IFNULL(scope_actor_id, 0)`)
 * that lets the UNIQUE active-window key treat "unscoped" (NULL scope) as a
 * distinct value rather than blocking NULL = NULL deduplication.
 *
 * `type_id` and `system_id` carry FK constraints into `invflux_identifier_types`
 * and `invflux_systems` (see the {@see $identifierType} / {@see $system}
 * relations), in addition to the application-side validation performed via
 * {@see \Nandan108\InvFlux\Contracts\Inventory\SubjectRegistrar} lookups.
 *
 * @api
 *
 * @psalm-suppress PossiblyUnusedProperty Properties are hydrated by attrecord from row data.
 */
#[Table(name: 'invflux_subject_identifiers')]
#[UniqueKey('uq_identifier_exact_window', columns: ['system_id', 'type_id', 'scope_actor_key', 'value', 'valid_to'])]
#[Index('idx_identifier_resolve_window', columns: ['system_id', 'type_id', 'scope_actor_key', 'value', 'valid_from', 'valid_to'])]
#[Index('idx_identifier_subject_window', columns: ['subject_id', 'system_id', 'type_id', 'scope_actor_key', 'valid_from', 'valid_to'])]
final class SubjectIdentifier extends Record
{
    #[Column(ColumnType::BigIntUnsigned, autoIncrement: true)]
    public ?int $id = null;

    #[Column(ColumnType::IntUnsigned)]
    public int $subject_id = 0;

    #[Column(ColumnType::SmallIntUnsigned)]
    public int $type_id = 0;

    #[Column(ColumnType::SmallIntUnsigned)]
    public int $system_id = 0;

    #[Column(ColumnType::VarChar, length: 255)]
    public string $value = '';

    /**
     * Database-computed: `value` reinterpreted as a number, or NULL when it isn't one.
     *
     * `value` holds every kind of identifier — a host row id, a SKU, a GTIN — so it is a string
     * column, and the identifiers that *are* numeric must still compare numerically: a host stores
     * `114697` and `0114697` as the same row. Casting at the join (`ON p.ID = CAST(si.value AS
     * UNSIGNED)`) buys that at the price of an index: the expression is not sargable, so the
     * optimizer scans the whole table once per outer row. Computing it here instead keeps the
     * numeric comparison and makes it indexable.
     *
     * `VIRTUAL`, because nothing ever reads it except through `idx_value_uint` — the index
     * materializes it, the table does not. The `REGEXP` guard is what makes the index selective:
     * without it every non-numeric identifier casts to `0` and lands in one enormous bucket. The
     * `{1,19}` bound keeps the cast inside `BIGINT UNSIGNED` range, so an absurdly long numeric
     * string yields NULL rather than a saturated value that could collide with a real id.
     */
    #[Column(
        ColumnType::BigIntUnsigned,
        nullable: true,
        generatedAs: "(CASE WHEN `value` REGEXP '^[0-9]{1,19}$' THEN CAST(`value` AS UNSIGNED) END)",
        generatedMode: GeneratedColumnMode::Virtual,
    )]
    #[Index('idx_value_uint')]
    public ?int $value_uint = null;

    #[Column(ColumnType::Bool, nullable: true)]
    public ?bool $is_primary = null;

    #[Column(ColumnType::IntUnsigned, nullable: true)]
    public ?int $scope_actor_id = null;

    /** Database-computed: IFNULL(scope_actor_id, 0). Treats unscoped (NULL) as 0 so it can participate in UNIQUE keys. */
    #[Column(
        ColumnType::IntUnsigned,
        generatedAs: 'IFNULL(scope_actor_id, 0)',
        generatedMode: GeneratedColumnMode::Stored,
    )]
    public int $scope_actor_key = 0;

    #[Column(ColumnType::DateTime, precision: 6)]
    public ?\DateTimeImmutable $valid_from = null;

    #[Column(
        ColumnType::DateTime,
        precision: 6,
        default: IdentifierValidity::OPEN_ENDED,
    )]
    public ?\DateTimeImmutable $valid_to = null;

    #[Relation(
        RelationType::ManyToOne,
        class: Subject::class,
        foreignKey: 'subject_id',
        onDelete: ForeignKeyAction::Cascade,
    )]
    public ?Subject $subject = null;

    #[Relation(
        RelationType::ManyToOne,
        class: ActorRecord::class,
        foreignKey: 'scope_actor_id',
        onDelete: ForeignKeyAction::Restrict,
    )]
    public ?ActorRecord $scopeActor = null;

    #[Relation(
        RelationType::ManyToOne,
        class: IdentifierTypeRecord::class,
        foreignKey: 'type_id',
        onDelete: ForeignKeyAction::Restrict,
    )]
    public ?IdentifierTypeRecord $identifierType = null;

    #[Relation(
        RelationType::ManyToOne,
        class: SystemRecord::class,
        foreignKey: 'system_id',
        onDelete: ForeignKeyAction::Restrict,
    )]
    public ?SystemRecord $system = null;

    #[\Override]
    public function beforeSave(): void
    {
        if (null === $this->valid_from) {
            $this->valid_from = new \DateTimeImmutable();
        }
        if (null === $this->valid_to) {
            $this->valid_to = new \DateTimeImmutable(IdentifierValidity::OPEN_ENDED);
        }
    }

    #[\Override]
    public function validate(): void
    {
        if ($this->subject_id <= 0) {
            throw new RecordValidationException(
                'SubjectIdentifier.subject_id must be a positive integer.',
                ['field' => 'subject_id'],
            );
        }
        if ($this->type_id <= 0) {
            throw new RecordValidationException(
                'SubjectIdentifier.type_id must be a positive integer.',
                ['field' => 'type_id'],
            );
        }
        if ($this->system_id <= 0) {
            throw new RecordValidationException(
                'SubjectIdentifier.system_id must be a positive integer.',
                ['field' => 'system_id'],
            );
        }
        if ('' === $this->value) {
            throw new RecordValidationException(
                'SubjectIdentifier.value must be a non-empty string.',
                ['field' => 'value'],
            );
        }
    }
}
