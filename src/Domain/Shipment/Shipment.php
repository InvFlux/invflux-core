<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Domain\Shipment;

use Nandan108\Attrecord\Attribute\Column;
use Nandan108\Attrecord\Attribute\Index;
use Nandan108\Attrecord\Attribute\LockTier;
use Nandan108\Attrecord\Attribute\Relation;
use Nandan108\Attrecord\Attribute\Table;
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
 * authoritative at-fulfilment unit cost** (the real COGS, stamped at the WAC
 * of the dispatch instant — see).
 *
 * **Schema is multi-shipment-per-order from the first migration** (BINARY(16)
 * UUIDv7 keys, `order_id` non-unique): native partial fulfillment,
 * multi-warehouse split, resend/reversal, and bulk-process all assume several
 * shipment rows per order, and order-atomic modelling is cheap now but
 * expensive to retrofit. **Tier split is in the *write paths*, not the schema:**
 * Essentials writes exactly one {@see ShipmentStatus::Processed} row per order at
 * dispatch-confirm (the single happy path); the per-shipment save/process
 * endpoint, partial UI, carrier hand-off, and the `resend_of_*` /
 * `reversal_of_*` exceptional flows are Pro.
 *
 * Carrier / tracking fields are nullable and stay empty on the Essentials happy path
 * (InvFlux is a well-behaved WC citizen: the WC status transition drives label
 * plugins; we don't own labels). They are populated by the Pro tracking-entry
 * control + `TrackingMetaWriter`.
 *
 * @api
 *
 * @psalm-suppress PossiblyUnusedProperty Properties are hydrated by attrecord from row data.
 */
#[Table(name: 'invflux_shipments')]
#[LockTier(24)]
final class Shipment extends Record
{
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
