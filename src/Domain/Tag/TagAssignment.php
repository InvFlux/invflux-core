<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Domain\Tag;

use Nandan108\Attrecord\Attribute\Column;
use Nandan108\Attrecord\Attribute\Index;
use Nandan108\Attrecord\Attribute\LockTier;
use Nandan108\Attrecord\Attribute\Relation;
use Nandan108\Attrecord\Attribute\Table;
use Nandan108\Attrecord\Attribute\UniqueKey;
use Nandan108\Attrecord\Enum\ColumnType;
use Nandan108\Attrecord\Enum\ForeignKeyAction;
use Nandan108\Attrecord\Enum\RelationType;
use Nandan108\Attrecord\Exception\RecordValidationException;
use Nandan108\Attrecord\Record;

/**
 * A {@see Tag} attached to a document — the **polymorphic** assignment set, keyed by the ref
 * system: `target_ref_type_id` + exactly one of `target_ref_id` (BINARY(16) UUID entities) or
 * `target_ref_int_id` (INT entities). The target has no FK, so per-entity cascade cleanup is
 * app-level. The `tag_id` FK cascades as a DB-level safety net, but the vocabulary is retired
 * (archived), never hard-deleted, so in practice assignments outlive a tag's retirement (kept
 * resolvable for the annotation audit).
 *
 * Two UNIQUE keys — one per target kind — enforce "a tag is attached at most once": a UUID
 * row leaves `target_ref_int_id` null (so the int key's NULL never collides) and vice-versa.
 *
 * @api
 *
 * @psalm-suppress PossiblyUnusedProperty Properties are hydrated by attrecord from row data.
 */
#[Table(name: 'invflux_tag_assignments')]
#[LockTier(29)]
#[UniqueKey('uniq_uuid_tag', columns: ['target_ref_type_id', 'target_ref_id', 'tag_id'])]
#[UniqueKey('uniq_int_tag', columns: ['target_ref_type_id', 'target_ref_int_id', 'tag_id'])]
#[Index('idx_tag', columns: ['tag_id'])]
final class TagAssignment extends Record
{
    #[Column(ColumnType::BigIntUnsigned, autoIncrement: true)]
    public ?int $id = null;

    #[Column(ColumnType::SmallIntUnsigned)]
    public int $target_ref_type_id = 0;

    #[Column(ColumnType::Binary, length: 16, nullable: true)]
    public ?string $target_ref_id = null;

    #[Column(ColumnType::IntUnsigned, nullable: true)]
    public ?int $target_ref_int_id = null;

    #[Column(ColumnType::BigIntUnsigned)]
    public int $tag_id = 0;

    #[Column(ColumnType::IntUnsigned, nullable: true)]
    public ?int $created_by_actor_id = null;

    #[Column(ColumnType::DateTime, precision: 6)]
    public ?\DateTimeImmutable $created_at = null;

    #[Relation(
        RelationType::ManyToOne,
        class: Tag::class,
        foreignKey: 'tag_id',
        onDelete: ForeignKeyAction::Cascade,
    )]
    public ?Tag $tag = null;

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
        if ($this->target_ref_type_id <= 0) {
            throw new RecordValidationException(
                'TagAssignment.target_ref_type_id must reference an invflux_ref_types.id.',
                ['field' => 'target_ref_type_id'],
            );
        }
        if ((null !== $this->target_ref_id) === (null !== $this->target_ref_int_id)) {
            throw new RecordValidationException(
                'TagAssignment must target exactly one of target_ref_id (UUID) or target_ref_int_id (INT).',
                ['field' => 'target_ref_id'],
            );
        }
        if ($this->tag_id <= 0) {
            throw new RecordValidationException(
                'TagAssignment.tag_id must reference an invflux_tags.id.',
                ['field' => 'tag_id'],
            );
        }
    }
}
