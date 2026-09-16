<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Domain\Procurement;

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
use Nandan108\InvFlux\Identity\RefTypeRecord;
use Nandan108\InvFlux\Identity\SurfaceRecord;

/**
 * A purchase-order event — the PO-side audit/history log. Mirrors
 * {@see \Nandan108\InvFlux\Domain\Order\OrderEvent}'s columns (polymorphic payload,
 * actor / surface / ref, correlation_id) but with an **`INT UNSIGNED` PK** (admin-minted,
 * not a UUID — PO events are back-office, not federation hot-path) and an `INT` `ref_id`.
 *
 * Built + emitted from the start (the OrderEvents principle); the read/audit surface is
 * Pro-gated.
 *
 * @api
 *
 * @psalm-suppress PossiblyUnusedProperty Properties are hydrated by attrecord from row data.
 */
#[Table(name: 'invflux_po_events')]
#[LockTier(37)]
final class PoEvent extends Record
{
    /**
     * Reserved `event_type` vocabulary for receipt + discrepancy **action** events — those not tied to a
     * status transition (status-landing events like `po.received` are owned by
     * {@see PurchaseOrderLifecycle}'s event map). Declared ahead of their emitters so the strings never
     * diverge into ad-hoc literals.
     */
    /** The purchase order was created (draft). */
    public const TYPE_CREATED = 'po.created';
    /** The gapless document number was minted and assigned (the Assign-number action). Payload: `{number}`. */
    public const TYPE_NUMBER_ASSIGNED = 'po.number_assigned';
    /** The expected delivery date (ETA) was revised. Payload: `{from: ?string, to: ?string}` (ISO dates). */
    public const TYPE_ETA_CHANGED = 'po.eta_changed';
    /**
     * One or more header fields other than the ETA alone were revised — the commercial terms
     * (Incoterm + place, shipping method, T&C), possibly together with the ETA. Payload:
     * `{fields: {<field>: {from: ?string, to: ?string}}}`, so every prior value stays recoverable.
     * The ETA on its own keeps {@see self::TYPE_ETA_CHANGED}, which the timeline renders as a date.
     */
    public const TYPE_HEADER_CHANGED = 'po.header_changed';
    /** A goods receipt was logged against an open session. Payload: per-line received/damaged qty. */
    public const TYPE_RECEIPT_LOGGED = 'receipt.logged';
    /** Close-short finalize wrote off a line remainder. Payload: shorted lines, qty, reason. */
    public const TYPE_SHORT_CLOSED = 'po.short_closed';
    /** A structured receiving discrepancy was opened (short / over / damaged / wrong-SKU). */
    public const TYPE_DISCREPANCY_RAISED = 'po.discrepancy_raised';
    /** A structured receiving discrepancy was resolved — accepted, quarantined, or returned. */
    public const TYPE_DISCREPANCY_RESOLVED = 'po.discrepancy_resolved';

    #[Column(ColumnType::IntUnsigned, autoIncrement: true)]
    public ?int $id = null;

    #[Column(ColumnType::IntUnsigned)]
    #[Index('idx_po')]
    public int $po_id = 0;

    /** `po.created`, `po.submitted`, `po.received`, `receipt.logged`, … */
    #[Column(ColumnType::VarChar, length: 40)]
    public string $event_type = '';

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

    /** Referenced doc id (INT, polymorphic — discriminated by ref_type_id; no hard FK). */
    #[Column(ColumnType::IntUnsigned, nullable: true)]
    public ?int $ref_id = null;

    /** Discriminated by `event_type` (raw JSON here; OrderEvent-style caster is a Pro refinement). */
    #[Column(ColumnType::Json, nullable: true)]
    public ?string $payload = null;

    #[Column(ColumnType::VarChar, length: 500, nullable: true)]
    public ?string $note = null;

    /** ULID grouping related events. */
    #[Column(ColumnType::Char, length: 26, nullable: true)]
    public ?string $correlation_id = null;

    #[Relation(
        RelationType::ManyToOne,
        class: PurchaseOrder::class,
        foreignKey: 'po_id',
        onDelete: ForeignKeyAction::Cascade,
    )]
    public ?PurchaseOrder $purchaseOrder = null;

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
        $now = new \DateTimeImmutable();
        if (null === $this->occurred_at) {
            $this->occurred_at = $now;
        }
        $this->recorded_at = $now;
    }

    #[\Override]
    public function validate(): void
    {
        if ($this->po_id <= 0) {
            throw new RecordValidationException(
                'PoEvent.po_id must be a positive integer.',
                ['field' => 'po_id', 'value' => $this->po_id],
            );
        }
        if ('' === trim($this->event_type)) {
            throw new RecordValidationException(
                'PoEvent.event_type must be a non-empty string.',
                ['field' => 'event_type'],
            );
        }
    }
}
