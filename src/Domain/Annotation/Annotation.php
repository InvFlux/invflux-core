<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Domain\Annotation;

use Nandan108\Attrecord\Attribute\Column;
use Nandan108\Attrecord\Attribute\Index;
use Nandan108\Attrecord\Attribute\LockTier;
use Nandan108\Attrecord\Attribute\Table;
use Nandan108\Attrecord\Enum\ColumnType;
use Nandan108\Attrecord\Exception\RecordValidationException;
use Nandan108\Attrecord\Record;
use Nandan108\InvFlux\Identity\RecordIdentity;

/**
 * One **immutable** annotation event on a document — a note, a tag change, or both.
 * Append-only: an edit is a *new* row with `version+1` under the same `thread_id`, never an
 * in-place mutation.
 *
 * **Polymorphic target.** The annotated document is referenced through the ref system —
 * `target_ref_type_id` (a code in `invflux_ref_types`) plus exactly one of `target_ref_id`
 * (BINARY(16) UUID entities, e.g. orders) or `target_ref_int_id` (INT entities, e.g. POs) —
 * the same dual encoding the inventory ledger uses for mixed UUID/INT referents. There is
 * therefore no per-target FK; cascade cleanup is app-level (a deletion-purge hook per entity
 * type), the conscious trade recorded in the arch doc §4.1/§11.
 *
 * `body` is the full note text at this version (null for a pure tag change). `tag_actions`
 * is the tag delta applied at this version: `{added: int[], removed: int[]}` of
 * `invflux_tags.id`. Diffs are **never stored** — computed at render time from adjacent
 * versions' `body`.
 *
 * @api
 *
 * @psalm-suppress PossiblyUnusedProperty Properties are hydrated by attrecord from row data.
 */
#[Table(name: 'invflux_annotations')]
#[LockTier(28)]
#[Index('idx_target_uuid', columns: ['target_ref_type_id', 'target_ref_id', 'occurred_at'])]
#[Index('idx_target_int', columns: ['target_ref_type_id', 'target_ref_int_id', 'occurred_at'])]
#[Index('idx_thread', columns: ['thread_id', 'version'])]
#[Index('idx_author', columns: ['author_actor_id', 'occurred_at'])]
final class Annotation extends Record
{
    public const ACTION_CREATED = 'created';
    public const ACTION_EDITED = 'edited';
    public const ACTION_DELETED = 'deleted';

    /** 16-byte binary UUIDv7 — minted on save via RecordIdentity. */
    #[Column(ColumnType::Binary, length: 16)]
    public ?string $id = null;

    #[Column(ColumnType::SmallIntUnsigned)]
    public int $target_ref_type_id = 0;

    /** Set for UUID-keyed targets (orders); null for INT targets. */
    #[Column(ColumnType::Binary, length: 16, nullable: true)]
    public ?string $target_ref_id = null;

    /** Set for INT-keyed targets (purchase orders); null for UUID targets. */
    #[Column(ColumnType::IntUnsigned, nullable: true)]
    public ?int $target_ref_int_id = null;

    /**
     * Groups the versions of one logical note. A fresh note's thread_id defaults to its own
     * id (single-version threads for pure tag changes never grow).
     */
    #[Column(ColumnType::Binary, length: 16)]
    public ?string $thread_id = null;

    #[Column(ColumnType::IntUnsigned)]
    public int $version = 1;

    #[Column(ColumnType::Enum, enumValues: [self::ACTION_CREATED, self::ACTION_EDITED, self::ACTION_DELETED])]
    public string $action = self::ACTION_CREATED;

    /** Full note text at this version; null for a pure tag-change annotation. */
    #[Column(ColumnType::Text, nullable: true)]
    public ?string $body = null;

    /**
     * Tag delta applied at this version — `{added: int[], removed: int[]}` of tag ids. Null
     * when the annotation touches no tags. JsonCaster auto-attaches for the JSON column.
     *
     * @var array{added?: list<int>, removed?: list<int>}|null
     */
    #[Column(ColumnType::Json, nullable: true)]
    public ?array $tag_actions = null;

    #[Column(ColumnType::IntUnsigned, nullable: true)]
    public ?int $author_actor_id = null;

    /** Logical time — drives timeline interleaving with OrderEvent/PoEvent. */
    #[Column(ColumnType::DateTime, precision: 6)]
    public ?\DateTimeImmutable $occurred_at = null;

    #[Column(ColumnType::DateTime, precision: 6, defaultExpr: 'CURRENT_TIMESTAMP(6)')]
    public ?\DateTimeImmutable $recorded_at = null;

    #[\Override]
    public function beforeSave(): void
    {
        if (null === $this->id) {
            $this->id = RecordIdentity::mintId();
        }
        if (null === $this->thread_id) {
            $this->thread_id = $this->id;
        }
        // attrecord's save() emits every non-generated column, so a null recorded_at is written
        // as an explicit NULL (the DB DEFAULT only fires when the column is omitted) and trips the
        // NOT NULL constraint. Set it here, matching OrderEvent/PoEvent.
        if (null === $this->recorded_at) {
            $this->recorded_at = new \DateTimeImmutable();
        }
        if (null === $this->occurred_at) {
            $this->occurred_at = $this->recorded_at;
        }
    }

    #[\Override]
    public function validate(): void
    {
        if ($this->target_ref_type_id <= 0) {
            throw new RecordValidationException(
                'Annotation.target_ref_type_id must reference an invflux_ref_types.id.',
                ['field' => 'target_ref_type_id'],
            );
        }
        $hasUuid = null !== $this->target_ref_id;
        $hasInt = null !== $this->target_ref_int_id;
        if ($hasUuid === $hasInt) {
            throw new RecordValidationException(
                'Annotation must target exactly one of target_ref_id (UUID) or target_ref_int_id (INT).',
                ['field' => 'target_ref_id'],
            );
        }
        if (null !== $this->target_ref_id && 16 !== \strlen($this->target_ref_id)) {
            throw new RecordValidationException(
                'Annotation.target_ref_id must be a 16-byte binary id.',
                ['field' => 'target_ref_id'],
            );
        }
        if ($this->version < 1) {
            throw new RecordValidationException(
                'Annotation.version must be >= 1.',
                ['field' => 'version'],
            );
        }
        // A `deleted` marker legitimately carries neither; any other action must carry a
        // note body, a tag delta, or both.
        if (self::ACTION_DELETED !== $this->action && null === $this->body && null === $this->tag_actions) {
            throw new RecordValidationException(
                'Annotation must carry a body, a tag delta, or both.',
                ['field' => 'body'],
            );
        }
    }
}
