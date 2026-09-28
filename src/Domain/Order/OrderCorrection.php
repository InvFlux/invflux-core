<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Domain\Order;

use Nandan108\Attrecord\Attribute\Column;
use Nandan108\Attrecord\Attribute\ForeignKey;
use Nandan108\Attrecord\Attribute\Index;
use Nandan108\Attrecord\Attribute\LockTier;
use Nandan108\Attrecord\Attribute\Relation;
use Nandan108\Attrecord\Attribute\Table;
use Nandan108\Attrecord\Enum\ColumnType;
use Nandan108\Attrecord\Enum\ForeignKeyAction;
use Nandan108\Attrecord\Enum\RelationType;
use Nandan108\Attrecord\Exception\RecordValidationException;
use Nandan108\Attrecord\Record;
use Nandan108\InvFlux\Identity\RecordIdentity;
use Nandan108\InvFlux\Util\Ulid;

/**
 * One correction against an order's fulfilment.
 *
 * Covers the full lifecycle: pre-shipment cancellations, pre-shipment write-offs,
 * late-payment shortfalls, and post-shipment returns. Per-instance fault
 * attribution lives on the linked {@see OrderCorrectionReason} (via `reason_id`);
 * shape/disposition lives on the linked {@see OrderCorrectionType} (via `type_id`).
 *
 * Lifecycle: `processed_at IS NULL` → unprocessed (engine queue); processed once stamped.
 * The correction row carries NO refund state — "owes a refund" is `refund_amount > 0`, and
 * the refund lifecycle lives entirely on order events (`refund.scheduled` →
 * `correction.refund_confirmed` / `refund.failed` / `refund.cancelled`).
 *
 * @api
 *
 * @psalm-suppress PossiblyUnusedProperty Properties are hydrated by attrecord from row data.
 */
#[Table(name: 'invflux_order_corrections')]
// The corrected line, named by its whole key. `order_id` is already carried for the order
// reference below, so the pair costs this table nothing beyond the number itself — and it makes
// "a correction against a line of some other order" unrepresentable, where before only the line
// was named and the order beside it was free to disagree with it.
#[ForeignKey(
    column: ['order_id', 'line_id'],
    references: OrderLine::class,
    onDelete: ForeignKeyAction::Restrict,
)]
#[LockTier(22)]
final class OrderCorrection extends Record
{
    /** 16-byte binary UUIDv7 — minted on save via RecordIdentity. */
    #[Column(ColumnType::Binary, length: 16)]
    public ?string $id = null;

    /** 16-byte binary UUIDv7 FK to invflux_orders.id. */
    #[Column(ColumnType::Binary, length: 16)]
    #[Index('idx_order')]
    public ?string $order_id = null;

    /**
     * The corrected line's number within {@see $order_id} — the order's numbering, so the two
     * columns together are the whole reference.
     *
     * Keeps the short name where {@see \Nandan108\InvFlux\Domain\Shipment\ShipmentLine} renamed
     * its equivalent to `order_line_id`: that table has lines of its own for a bare `line_id` to
     * be confused with, and a correction has none.
     *
     * `idx_line` is dropped with the widening — `(order_id, line_id)` is now served by the foreign
     * key's own index, and an index on the number alone would group line 3 of every order in the
     * store together, which answers no question anyone asks.
     */
    #[Column(ColumnType::SmallIntUnsigned)]
    public int $line_id = 0;

    #[Column(ColumnType::TinyIntUnsigned)]
    public int $type_id = 0;

    #[Column(ColumnType::TinyIntUnsigned, nullable: true)]
    public ?int $reason_id = null;

    #[Column(ColumnType::SmallIntUnsigned, default: 0)]
    public int $qty = 0;

    #[Column(ColumnType::Decimal, precision: 10, scale: 2, default: '0.00')]
    public string $refund_amount = '0.00';

    #[Column(ColumnType::Text, nullable: true)]
    public ?string $note = null;

    #[Column(ColumnType::BigIntUnsigned, default: 0)]
    public int $created_by = 0;

    #[Column(ColumnType::DateTime, nullable: true, precision: 6)]
    public ?\DateTimeImmutable $created_at = null;

    #[Column(ColumnType::DateTime, nullable: true, precision: 6)]
    #[Index('idx_processed')]
    public ?\DateTimeImmutable $processed_at = null;

    #[Column(ColumnType::BigIntUnsigned, nullable: true)]
    public ?int $stock_issue_id = null;

    /**
     * ULID linking this correction to a logical action (e.g. one LPSC resolution
     * creating N corrections). Carried forward to the `correction.*` and `refund.*`
     * events emitted as the correction progresses, so the timeline can group them.
     *
     * Grouping only. To recognise a correction a re-fired hook already created, use
     * {@see $idempotency_key} — a correlation is minted fresh per attempt, so it can
     * never answer "have I done this before".
     */
    #[Column(ColumnType::Char, length: 26, nullable: true)]
    #[Index('idx_correlation')]
    public ?string $correlation_id = null;

    /**
     * Stable, caller-defined name for the *logical operation* that produced this
     * correction — e.g. `foreign_refund:{refundId}` for a WooCommerce-side refund. A
     * writer whose entrypoint may fire more than once looks its own corrections up by
     * this key to skip the processed ones and resume the unprocessed ones.
     *
     * Deliberately separate from {@see $correlation_id}: one identifies *this run*, the
     * other identifies *the operation across runs*. Overloading the correlation for both
     * silently broke the foreign-refund path — the tag isn't a ULID, and every event the
     * correction emitted then failed validation far from the writer that set it.
     *
     * Semantic and versionable, in the spirit of `IdempotencyStore`'s operation keys.
     */
    #[Column(ColumnType::VarChar, length: 191, nullable: true)]
    #[Index('idx_idempotency_key')]
    public ?string $idempotency_key = null;

    /**
     * 16-byte binary UUIDv7 FK to the *decision-tier* {@see OrderEvent} that
     * authorised this correction (e.g. an `lpsc.resolved` event from
     * `LpscCorrectionWriter`, a future `cs.approved` from a CS workbench batch).
     *
     * Null while the correction is in the *proposed* phase — i.e. created by floor
     * staff but not yet bundled into a reviewed decision. The MPB pattern: floor
     * staff create proposals, CS reviews + bundles them, the bundle gets an
     * `lpsc.resolved`/`cs.approved` event, and this FK stamps each correction with
     * the decision id at bundling time.
     *
     * `event_tier='decision'` is enforced at write time by the bundling code; not
     * by attrecord (no cross-table validator).
     */
    #[Column(ColumnType::Binary, length: 16, nullable: true)]
    #[Index('idx_parent_event')]
    public ?string $parent_event_id = null;

    #[Relation(
        RelationType::ManyToOne,
        class: Order::class,
        foreignKey: 'order_id',
        onDelete: ForeignKeyAction::Cascade,
    )]
    public ?Order $order = null;

    // Hydration only — the constraint itself is the multi-column #[ForeignKey] on the class, which
    // a #[Relation] cannot express, so this carries `emitFk: false` rather than declaring a second
    // one over a single column. attrecord refuses that single-column form outright now: naming one
    // member of a two-column key references a *prefix*, which the engines disagree about three
    // ways, so what used to be a silent near-miss is a startup error.
    //
    // RESTRICT there, unlike the order edge above. A correction is a settled monetary record — it
    // names a refund that was issued — so the disappearance of the line it corrects must not take
    // it with it. The projection reconciler deletes a line whose source item vanished, and the
    // test it applies is the line's own counters; a reinstated cancellation leaves `qty_corrected`
    // back at zero while both processed corrections survive, so those counters read "unreferenced"
    // for a line two refunds point at. RESTRICT makes that a refusal instead of a silent loss.
    //
    // The order edge stays CASCADE: an order genuinely going away takes its whole projection, and
    // nothing in the adapter deletes one — a host deletion *retires* the projection instead.
    #[Relation(
        RelationType::ManyToOne,
        class: OrderLine::class,
        foreignKey: 'line_id',
        emitFk: false,
    )]
    public ?OrderLine $line = null;

    #[Relation(
        RelationType::ManyToOne,
        class: OrderCorrectionType::class,
        foreignKey: 'type_id',
        onDelete: ForeignKeyAction::Restrict,
    )]
    public ?OrderCorrectionType $type = null;

    #[Relation(
        RelationType::ManyToOne,
        class: OrderCorrectionReason::class,
        foreignKey: 'reason_id',
        onDelete: ForeignKeyAction::SetNull,
    )]
    public ?OrderCorrectionReason $reason = null;

    /**
     * The decision-tier {@see OrderEvent} that authorised this correction.
     * SetNull on delete because corrections survive an event being unwound
     * (events are append-only in practice, but the FK action is the conservative
     * choice — losing the link is preferable to cascading correction deletes).
     */
    #[Relation(
        RelationType::ManyToOne,
        class: OrderEvent::class,
        foreignKey: 'parent_event_id',
        onDelete: ForeignKeyAction::SetNull,
    )]
    public ?OrderEvent $parentEvent = null;

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

    #[\Override]
    public function validate(): void
    {
        if (null === $this->order_id || 16 !== \strlen($this->order_id)) {
            throw new RecordValidationException(
                'OrderCorrection.order_id must be a 16-byte binary UUIDv7 referencing invflux_orders.id.',
                ['field' => 'order_id'],
            );
        }
        if ($this->line_id <= 0) {
            throw new RecordValidationException(
                'OrderCorrection.line_id must be a positive order-line number within order_id.',
                ['field' => 'line_id', 'value' => $this->line_id],
            );
        }
        if ($this->type_id <= 0) {
            throw new RecordValidationException(
                'OrderCorrection.type_id must be a positive integer (FK to invflux_order_correction_types.id).',
                ['field' => 'type_id', 'value' => $this->type_id],
            );
        }
        if (null !== $this->reason_id && $this->reason_id <= 0) {
            throw new RecordValidationException(
                'OrderCorrection.reason_id must be null or a positive integer.',
                ['field' => 'reason_id', 'value' => $this->reason_id],
            );
        }
        if ($this->qty < 0) {
            throw new RecordValidationException(
                'OrderCorrection.qty must be non-negative.',
                ['field' => 'qty', 'value' => $this->qty],
            );
        }
        if (null !== $this->parent_event_id && 16 !== \strlen($this->parent_event_id)) {
            throw new RecordValidationException(
                'OrderCorrection.parent_event_id must be a 16-byte binary UUIDv7 when set.',
                ['field' => 'parent_event_id'],
            );
        }
        // Validate here, at the write, rather than leaving it to the OrderEvent this value is
        // copied onto: a non-ULID correlation stored fine (it fits CHAR(26)) and only blew up
        // later, inside a hook that swallowed the throw — so a foreign refund silently skipped
        // its stock movement. Same rule, enforced where the bad value enters.
        if (null !== $this->correlation_id && !Ulid::isValid($this->correlation_id)) {
            throw new RecordValidationException(
                'OrderCorrection.correlation_id must be a valid ULID when set; '
                .'a name for the operation belongs in idempotency_key.',
                ['field' => 'correlation_id', 'value' => $this->correlation_id],
            );
        }
    }

    /** True if no slot movement has yet been applied. */
    public function isUnprocessed(): bool
    {
        return null === $this->processed_at;
    }
}
