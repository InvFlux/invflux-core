<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Domain\Order;

use Nandan108\Attrecord\Attribute\Column;
use Nandan108\Attrecord\Attribute\Index;
use Nandan108\Attrecord\Attribute\LockTier;
use Nandan108\Attrecord\Attribute\Relation;
use Nandan108\Attrecord\Attribute\Table;
use Nandan108\Attrecord\Enum\ColumnType;
use Nandan108\Attrecord\Enum\ForeignKeyAction;
use Nandan108\Attrecord\Enum\RelationType;
use Nandan108\Attrecord\Exception\RecordValidationException;
use Nandan108\Attrecord\Record;
use Nandan108\InvFlux\Identity\ActorRecord;
use Nandan108\InvFlux\Identity\RecordIdentity;
use Nandan108\InvFlux\Identity\RefTypeRecord;
use Nandan108\InvFlux\Identity\SurfaceRecord;
use Nandan108\InvFlux\Util\Ulid;

/**
 * One row of the append-only order-events timeline.
 *
 * See arch-order-events for schema rationale,
 * event vocabulary (`OrderEventType`), and invariants. The repository
 * (`OrderEventStore`) exposes only `append` + readers — there is intentionally no
 * update path. Reversals are new events with the same `correlation_id`.
 *
 * `flags` and `payload` are JSON columns cast to PHP arrays/VOs by attrecord: `flags` via the
 * auto-attached {@see \Nandan108\Attrecord\Caster\JsonCaster} (array-typed), `payload` via the
 * discriminated {@see OrderEventPayloadCaster}. Callers read/write typed values, not JSON strings.
 *
 * @api
 *
 * @psalm-suppress PossiblyUnusedProperty Properties are hydrated by attrecord from row data.
 */
#[Table(name: 'invflux_order_events')]
#[LockTier(23)]
#[Index('idx_order_time', columns: ['order_id', 'occurred_at', 'id'])]
#[Index('idx_event_time', columns: ['event_type', 'occurred_at'])]
#[Index('idx_ref', columns: ['ref_type_id', 'ref_id'])]
#[Index('idx_actor', columns: ['actor_id', 'occurred_at'])]
#[Index('idx_surface', columns: ['surface_id', 'occurred_at'])]
final class OrderEvent extends Record
{
    /** 16-byte binary UUIDv7 — minted on save via RecordIdentity. */
    #[Column(ColumnType::Binary, length: 16)]
    public ?string $id = null;

    /** 16-byte binary UUIDv7 FK to invflux_orders.id. */
    #[Column(ColumnType::Binary, length: 16)]
    public ?string $order_id = null;

    /** OrderEventType value (string-encoded). */
    #[Column(ColumnType::VarChar, length: 40)]
    public string $event_type = '';

    /**
     * Coarse classification of {@see $event_type}; auto-derived in
     * {@see beforeSave()} from `OrderEventType::tier()`. Callers should NOT set
     * this manually — it exists as a column for indexed projection queries
     * ("all decision events on order X").
     */
    #[Column(ColumnType::Enum, enumValues: ['lifecycle', 'decision', 'execution', 'ancillary'])]
    public string $event_tier = '';

    #[Column(ColumnType::DateTime, precision: 6)]
    public ?\DateTimeImmutable $occurred_at = null;

    #[Column(ColumnType::DateTime, precision: 6, defaultExpr: 'CURRENT_TIMESTAMP(6)')]
    public ?\DateTimeImmutable $recorded_at = null;

    #[Column(ColumnType::IntUnsigned, nullable: true)]
    public ?int $actor_id = null;

    #[Column(ColumnType::SmallIntUnsigned, nullable: true)]
    public ?int $surface_id = null;

    #[Column(ColumnType::SmallIntUnsigned, nullable: true)]
    public ?int $ref_type_id = null;

    /**
     * 16-byte binary UUIDv7 reference to the document this event is about
     * (correction, return, etc.). Only UUID-keyed referents are stored here;
     * INT-keyed refs go through dedicated typed columns elsewhere.
     */
    #[Column(ColumnType::Binary, length: 16, nullable: true)]
    public ?string $ref_id = null;

    /** @var array<string, mixed>|null JsonCaster auto-attaches on this array-typed Json column. */
    #[Column(ColumnType::Json, nullable: true)]
    public ?array $flags = null;

    /**
     * Polymorphic by `event_type`: monetary types (per {@see OrderEventType::isMonetary()})
     * carry a {@see MonetaryEventPayload} VO; non-monetary types carry a free-form
     * associative array. The {@see OrderEventPayloadCaster} reads the sibling
     * `event_type` column to dispatch on read; on write it JSON-encodes whichever
     * shape the property holds.
     *
     * @var MonetaryEventPayload|array<string, mixed>|null
     */
    #[Column(ColumnType::Json, nullable: true)]
    #[OrderEventPayloadCaster]
    public MonetaryEventPayload | array | null $payload = null;

    #[Column(ColumnType::VarChar, length: 500, nullable: true)]
    public ?string $note = null;

    #[Column(ColumnType::Char, length: 26, nullable: true)]
    #[Index('idx_correlation')]
    public ?string $correlation_id = null;

    #[Relation(
        RelationType::ManyToOne,
        class: Order::class,
        foreignKey: 'order_id',
        onDelete: ForeignKeyAction::Cascade,
    )]
    public ?Order $order = null;

    #[Relation(
        RelationType::ManyToOne,
        class: ActorRecord::class,
        foreignKey: 'actor_id',
        onDelete: ForeignKeyAction::SetNull,
    )]
    public ?ActorRecord $actor = null;

    #[Relation(
        RelationType::ManyToOne,
        class: SurfaceRecord::class,
        foreignKey: 'surface_id',
        onDelete: ForeignKeyAction::SetNull,
    )]
    public ?SurfaceRecord $surface = null;

    #[Relation(
        RelationType::ManyToOne,
        class: RefTypeRecord::class,
        foreignKey: 'ref_type_id',
        onDelete: ForeignKeyAction::SetNull,
    )]
    public ?RefTypeRecord $refType = null;

    #[\Override]
    public function beforeSave(): void
    {
        if (null === $this->id) {
            $this->id = RecordIdentity::mintId();
        }
        if (null === $this->recorded_at) {
            $this->recorded_at = new \DateTimeImmutable();
        }
        if (null === $this->occurred_at) {
            $this->occurred_at = $this->recorded_at;
        }
        // Auto-derive event_tier from event_type. validate() enforces consistency
        // if a caller explicitly set a mismatched tier.
        if ('' === $this->event_tier) {
            $type = OrderEventType::tryFrom($this->event_type);
            if (null !== $type) {
                $this->event_tier = $type->tier()->value;
            }
        }
    }

    #[\Override]
    public function validate(): void
    {
        if (null === $this->order_id || 16 !== \strlen($this->order_id)) {
            throw new RecordValidationException(
                'OrderEvent.order_id must be a 16-byte binary UUIDv7 referencing invflux_orders.id.',
                ['field' => 'order_id'],
            );
        }
        $type = OrderEventType::tryFrom($this->event_type);
        if (null === $type) {
            throw new RecordValidationException(
                sprintf('OrderEvent.event_type (%s) is not a known OrderEventType value.', $this->event_type),
                ['field' => 'event_type', 'value' => $this->event_type],
            );
        }
        $expectedTier = $type->tier()->value;
        // Auto-derive when empty (validate() may be called standalone, before beforeSave()).
        if ('' === $this->event_tier) {
            $this->event_tier = $expectedTier;
        } elseif ($this->event_tier !== $expectedTier) {
            throw new RecordValidationException(
                sprintf(
                    'OrderEvent.event_tier (%s) does not match OrderEventType::%s tier (%s).',
                    $this->event_tier,
                    $type->name,
                    $expectedTier,
                ),
                ['field' => 'event_tier', 'value' => $this->event_tier, 'expected' => $expectedTier],
            );
        }
        if (null !== $this->correlation_id && !Ulid::isValid($this->correlation_id)) {
            throw new RecordValidationException(
                'OrderEvent.correlation_id must be a valid ULID when set.',
                ['field' => 'correlation_id', 'value' => $this->correlation_id],
            );
        }
        if ($type->isMonetary() && !$this->payload instanceof MonetaryEventPayload) {
            throw new RecordValidationException(
                sprintf(
                    'OrderEvent.payload for monetary type %s must be a MonetaryEventPayload instance; got %s.',
                    $type->name,
                    null === $this->payload ? 'null' : get_debug_type($this->payload),
                ),
                ['field' => 'payload', 'event_type' => $this->event_type],
            );
        }
    }
}
