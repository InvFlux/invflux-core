<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Domain\Order;

use Nandan108\Attrecord\Attribute\Column;
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
use Nandan108\InvFlux\Identity\RecordIdentity;

/**
 * One order-level charge — an amount on a customer order that is not the goods: shipping, a
 * payment-method fee, packing, a delivery option such as a signature.
 *
 * **InvFlux never sets one.** The source system's checkout does, and this row mirrors it: one row
 * per charge item in the source order, keyed on that item's id so a re-projection updates rather
 * than duplicates, carrying the amount and tax as the source stated them. The source remains the
 * only writer of the money.
 *
 * What InvFlux adds is the {@see $kind}. A source system rarely records one, so it is decided when
 * the charge is first projected and stored, never recomputed: a later change to how charges are
 * classified applies to the orders that follow, never to the ones already taken.
 *
 * **Order-level only.** A charge bought per item — gift wrap on one line — belongs to that line and
 * is refunded with its units; it is not a row here, whatever item type the source used for it.
 *
 * **Signed.** A source may record a discount as a negative charge, and it is kept with its sign, so
 * the sum of an order's charges is what the customer paid beyond the goods.
 *
 * Shipping appears here too, as a `freight` charge, beside its {@see OrderShipping} row: that one
 * carries what is operational about the shipment, this one what it cost, so a refund has one rule for
 * every charge.
 *
 * **Lock tier 47** — a sibling of {@see OrderLine} (21) and {@see OrderShipping} (46) under the same
 * order, and siblings are what a projection writer locks in one call, which needs distinct tiers.
 *
 * @api
 *
 * @psalm-suppress PossiblyUnusedProperty Properties are hydrated by attrecord from row data.
 */
#[Table(name: 'invflux_order_charges')]
#[LockTier(47)]
final class OrderCharge extends Record
{
    /** 16-byte binary UUIDv7 — minted on save via RecordIdentity. */
    #[Column(ColumnType::Binary, length: 16)]
    public ?string $id = null;

    /** 16-byte binary UUIDv7 FK to invflux_orders.id. */
    #[Column(ColumnType::Binary, length: 16)]
    #[UniqueKey('uniq_order_charge')]
    public ?string $order_id = null;

    /**
     * The source system's identifier for this charge item (e.g. a WooCommerce `order_item_id`).
     * Unique per order, so re-projecting an order updates its charges in place.
     */
    #[Column(ColumnType::VarChar, length: 64)]
    #[UniqueKey('uniq_order_charge')]
    public string $external_ref = '';

    /**
     * What the charge is for. Decided when the row is first written and never recomputed — see the
     * class docblock. Stored as its string backing, so a kind added later needs no schema change.
     */
    #[Column(ColumnType::VarChar, length: 16, default: OrderChargeKind::Other)]
    #[EnumCaster(OrderChargeKind::class)]
    public OrderChargeKind $kind = OrderChargeKind::Other;

    /** What the customer was shown. Display only. */
    #[Column(ColumnType::VarChar, length: 191, default: '')]
    public string $name = '';

    /** The charge before tax, in the order's currency. Negative for a discount recorded as a charge. */
    #[Column(ColumnType::Decimal, precision: 10, scale: 2, default: '0.00')]
    public string $amount = '0.00';

    /** The tax on it, in the order's currency. `0.00` for an untaxed charge. */
    #[Column(ColumnType::Decimal, precision: 10, scale: 2, default: '0.00')]
    public string $tax = '0.00';

    #[Relation(
        RelationType::ManyToOne,
        class: Order::class,
        foreignKey: 'order_id',
        onDelete: ForeignKeyAction::Cascade,
    )]
    public ?Order $order = null;

    #[\Override]
    public function beforeSave(): void
    {
        if (null === $this->id) {
            $this->id = RecordIdentity::mintId();
        }
    }

    #[\Override]
    public function validate(): void
    {
        if (null === $this->order_id || 16 !== \strlen($this->order_id)) {
            throw new RecordValidationException(
                'OrderCharge.order_id must be a 16-byte binary UUIDv7 referencing invflux_orders.id.',
                ['field' => 'order_id'],
            );
        }

        if ('' === $this->external_ref) {
            throw new RecordValidationException(
                'OrderCharge.external_ref must identify the charge in the source system.',
                ['field' => 'external_ref'],
            );
        }

        foreach (['amount' => $this->amount, 'tax' => $this->tax] as $field => $value) {
            if (!is_numeric($value)) {
                throw new RecordValidationException(
                    \sprintf('OrderCharge.%s must be a decimal amount, got "%s".', $field, $value),
                    ['field' => $field],
                );
            }
        }
    }
}
