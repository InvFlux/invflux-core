<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Domain\Shipment;

use Nandan108\Attrecord\Attribute\Column;
use Nandan108\Attrecord\Attribute\Index;
use Nandan108\Attrecord\Attribute\LockTier;
use Nandan108\Attrecord\Attribute\Relation;
use Nandan108\Attrecord\Attribute\Table;
use Nandan108\Attrecord\Attribute\UniqueKey;
use Nandan108\Attrecord\Caster\EnumCaster;
use Nandan108\Attrecord\Enum\ColumnType;
use Nandan108\Attrecord\Enum\ForeignKeyAction;
use Nandan108\Attrecord\Enum\RelationType;
use Nandan108\Attrecord\Exception\RecordValidationException;
use Nandan108\Attrecord\Record;
use Nandan108\InvFlux\Domain\Order\Order;
use Nandan108\InvFlux\Domain\Procurement\DocumentParty;
use Nandan108\InvFlux\Identity\RecordIdentity;

/**
 * One outbound shipment of (part of) an order — the dispatch analogue of a
 * goods receipt. The row records *what physically left and when*; its
 * {@see ShipmentLine} children carry the per-line quantity **and the
 * authoritative at-fulfillment unit cost** (the real COGS, stamped at the WAC
 * of the dispatch instant — see).
 *
 * **Schema is multi-shipment-per-order from the first migration** (BINARY(16)
 * UUIDv7 keys, `order_id` non-unique): native partial fulfillment,
 * multi-warehouse split, resend/reversal, and bulk-process all assume several
 * shipment rows per order, and order-atomic modeling is cheap now but
 * expensive to retrofit. **What differs between installs is the *write paths*, not the schema:**
 * the base install writes one {@see ShipmentStatus::Processed} row per dispatch it confirms, one per
 * shipment recorded from the host's own fulfillment records, and marks a shipment
 * {@see ShipmentStatus::Reversed} when its dispatch is undone; a per-shipment save/process endpoint,
 * partial dispatch from InvFlux's own screens and resends are contributed by add-ons.
 *
 * Carrier / tracking fields are nullable. They are filled when the operator types a tracking
 * number at Ship, when a label integration produces one, or when the shipment is recorded from a
 * record some other system made ({@see $source_system}); InvFlux itself never generates a label.
 *
 * @api
 *
 * @psalm-suppress PossiblyUnusedProperty Properties are hydrated by attrecord from row data.
 */
#[Table(name: 'invflux_shipments')]
// Redundant by itself — `id` is already unique, so this constrains nothing new. It exists to be a
// foreign-key *target*: {@see ShipmentLine} carries both `shipment_id` and `order_id`, and without
// a key over the pair nothing makes the two agree, so a shipment line could name a shipment for
// one order and an order line from another. Pairing them against this turns that from a rule a
// writer has to remember into one the database keeps.
#[UniqueKey('uniq_id_order', columns: ['id', 'order_id'])]
// One shipment per external record. Recording a shipment someone else made is driven by events that
// can arrive twice — a re-fired hook, a retried webhook — and the second must find the first rather
// than move stock again. A key, not a lookup-then-insert, because two concurrent fires both pass the
// lookup. Shipments made here leave `external_ref` null, and a unique key admits any number of those.
#[UniqueKey('uniq_source_ref', columns: ['source_system', 'external_ref'])]
#[LockTier(24)]
final class Shipment extends Record
{
    /** {@see $source_system} of a shipment confirmed in InvFlux. */
    public const SOURCE_INVFLUX = 'invflux';

    /** 16-byte binary UUIDv7 — minted on save via RecordIdentity. */
    #[Column(ColumnType::Binary, length: 16)]
    public ?string $id = null;

    /** 16-byte binary UUIDv7 FK to invflux_orders.id (non-unique — many shipments per order). */
    #[Column(ColumnType::Binary, length: 16)]
    #[Index('idx_order')]
    public ?string $order_id = null;

    /** ENUM value list derived from the caster's enum. */
    #[Column(ColumnType::Enum, default: ShipmentStatus::Saved)]
    #[EnumCaster(ShipmentStatus::class)]
    public ShipmentStatus $status = ShipmentStatus::Saved;

    #[Column(ColumnType::VarChar, length: 50, default: '')]
    public string $carrier_code = '';

    #[Column(ColumnType::VarChar, length: 50, default: '')]
    public string $service_type = '';

    /** Additional carrier services/options, selected in InvFlux or imported from a shipping plugin. */
    #[Column(ColumnType::Json, nullable: true)]
    public ?string $service_options = null;

    #[Column(ColumnType::VarChar, length: 200, nullable: true)]
    public ?string $tracking_number = null;

    #[Column(ColumnType::VarChar, length: 2000, nullable: true)]
    public ?string $label_url = null;

    /** ID/reference owned by an external shipping plugin, if any. */
    #[Column(ColumnType::VarChar, length: 200, nullable: true)]
    public ?string $external_ref = null;

    /**
     * Which system made this shipment: `invflux` when it was confirmed here, otherwise the system
     * whose record it was recorded from — a code the adapter recording it chooses, one per kind of record.
     *
     * It is what stops a two-way mirror from looping. A shipment recorded *from* another system is
     * never written back *to* it — that system already holds the record, so writing it would create
     * a second one, which would be recorded again. With {@see $external_ref} it also names the record
     * a shipment came from, uniquely.
     */
    #[Column(ColumnType::VarChar, length: 50, default: self::SOURCE_INVFLUX)]
    public string $source_system = self::SOURCE_INVFLUX;

    /** Tracking entered by hand (operator) vs supplied by a carrier integration. */
    #[Column(ColumnType::Bool, default: false)]
    public bool $manual_tracking = false;

    /**
     * The party that was on the label — where this parcel actually went.
     *
     * **Never rewritten.** The order's address is current intent and moves when someone corrects it;
     * this is historical fact and a courier is holding the proof. Overwriting it after a redirection
     * would make the record disagree with an object in the world, and that distinction decides a
     * carrier dispute: *your label was wrong* and *you misdelivered a correct label* are different
     * arguments, and only the first is the merchant's problem.
     *
     * This is also what makes the order's shipping address safe to correct after dispatch — the case
     * the whole design exists for, since a parcel that bounced *because the address was wrong* is the
     * commonest reason to edit one.
     *
     * Nullable, and it stays null on every shipment that predates this column. A backfill would have
     * to read the order's *current* party, which is precisely the value that may have moved since —
     * so it would state, with a straight face, that a parcel went somewhere it did not.
     */
    #[Column(ColumnType::BigInt, nullable: true)]
    public ?int $ship_to_party_id = null;

    /**
     * Pro exceptional flows — logical references to another invflux_shipments.id.
     * Kept as plain nullable binary (no self-FK) to keep the CREATE TABLE
     * self-contained; the Essentials path never sets them.
     */
    #[Column(ColumnType::Binary, length: 16, nullable: true)]
    public ?string $resend_of_shipment_id = null;

    #[Column(ColumnType::Binary, length: 16, nullable: true)]
    public ?string $reversal_of_shipment_id = null;

    /** invflux_actors.id of the operator who created the shipment (soft attribution, no FK). */
    #[Column(ColumnType::IntUnsigned, nullable: true)]
    public ?int $created_by_actor_id = null;

    #[Column(ColumnType::DateTime, precision: 6)]
    public ?\DateTimeImmutable $created_at = null;

    /** Set when the shipment transitions to `processed` (the COGS-recognition moment). */
    #[Column(ColumnType::DateTime, precision: 6, nullable: true)]
    public ?\DateTimeImmutable $processed_at = null;

    #[Column(ColumnType::DateTime, precision: 6, nullable: true)]
    public ?\DateTimeImmutable $reversed_at = null;

    #[Column(ColumnType::IntUnsigned, nullable: true)]
    public ?int $reversed_by_actor_id = null;

    #[Relation(
        RelationType::ManyToOne,
        class: Order::class,
        foreignKey: 'order_id',
        onDelete: ForeignKeyAction::Cascade,
    )]
    public ?Order $order = null;

    /**
     * `RESTRICT` like every other pointer at a party: a row a shipment still names is not an orphan,
     * and this is the reference that keeps the address a parcel went to from being collected once
     * the order has moved on.
     */
    #[Relation(
        RelationType::ManyToOne,
        class: DocumentParty::class,
        foreignKey: 'ship_to_party_id',
        onDelete: ForeignKeyAction::Restrict,
    )]
    public ?DocumentParty $shipToParty = null;

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
                'Shipment.order_id must be a 16-byte binary UUIDv7 referencing invflux_orders.id.',
                ['field' => 'order_id'],
            );
        }
        // $status is enum-typed (EnumCaster) — the type system guarantees a valid ShipmentStatus.
    }
}
