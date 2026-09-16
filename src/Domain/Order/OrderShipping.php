<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Domain\Order;

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
use Nandan108\InvFlux\Identity\RecordIdentity;

/**
 * One shipping arrangement on an order — the carrier service a set of goods travels by.
 *
 * **This is a sibling of {@see OrderLine}, not a column on it.** A shipping arrangement is chosen
 * per *package*, and a source system may divide one order into several: a split cart, an item
 * shipped separately, goods leaving from different stock. So the relationship to the order is
 * one-to-many, and a model that stored the method on the order row could not express the second
 * package at all — nor could it say which method the second one travelled by.
 *
 * That most orders in most stores carry exactly one of these is a fact about those stores, not
 * about the model. A projection that collapsed the collection because a sample happened to be
 * uniform would be unable to represent the case it was built to handle the moment one appeared.
 *
 * **What it is for.** The chosen service is operational information a packer needs *before*
 * packing: a contracted service may impose packaging obligations, dimensional limits or a handover
 * cut-off. Discovering that at the label printer is discovering it after the work is done.
 *
 * **Snapshots, deliberately.** `method_id` and `method_title` record what was chosen when the order
 * was placed. A merchant renaming a shipping method, or retiring it, does not rewrite how orders
 * already placed were sent. This mirrors {@see OrderLine::$sku} and {@see OrderLine::$name}.
 *
 * `method_id` is the stable machine identity (the source system's instance key, e.g.
 * `flat_rate:3`); `method_title` is what the customer was shown. **Key on the former and display
 * the latter** — the title is merchant-editable prose and two distinct services can share one, so
 * grouping or filtering by title silently conflates them.
 *
 * **Lock tier 46, not {@see OrderLine}'s 21**, even though the two are siblings under the same order.
 *
 * `LockSet::acquire()` throws `LockTierConflictException` when two of its targets share a tier —
 * tiers are what *order* acquisition, so a shared one has no defined order between the tables. Any
 * transaction that locks an order's lines and its shipping arrangements together, which is the
 * natural thing for a projection writer to do, would have failed outright.
 *
 * Being a sibling is exactly the reason to separate them, then: siblings are what gets locked in
 * one call. Core's records hold tiers 20–59 and add-ons start at 60, so the two cannot collide.
 *
 * @api
 *
 * @psalm-suppress PossiblyUnusedProperty Properties are hydrated by attrecord from row data.
 */
#[Table(name: 'invflux_order_shipping')]
#[LockTier(46)]
final class OrderShipping extends Record
{
    /** 16-byte binary UUIDv7 — minted on save via RecordIdentity. */
    #[Column(ColumnType::Binary, length: 16)]
    public ?string $id = null;

    /** 16-byte binary UUIDv7 FK to invflux_orders.id. */
    #[Column(ColumnType::Binary, length: 16)]
    #[UniqueKey('uniq_order_shipping')]
    public ?string $order_id = null;

    /**
     * The source system's identifier for this shipping arrangement (e.g. a WooCommerce
     * `order_item_id`). Unique per order, so re-projecting an order updates its arrangements in
     * place rather than duplicating them.
     */
    #[Column(ColumnType::VarChar, length: 64)]
    #[UniqueKey('uniq_order_shipping')]
    public string $external_ref = '';

    /**
     * Stable machine identity of the chosen service — the instance key, not the method type, so two
     * differently-configured instances of one method stay distinct.
     *
     * Indexed because this is what the dispatch queue filters and counts on. `null` where the
     * source system recorded an arrangement without one, which is rare but not impossible: an
     * order imported or edited by hand can carry a shipping line with a title and nothing else.
     */
    #[Column(ColumnType::VarChar, length: 96, nullable: true)]
    #[Index('idx_order_shipping_method')]
    public ?string $method_id = null;

    /** What the customer was shown. Display only — never key on this; see the class docblock. */
    #[Column(ColumnType::VarChar, length: 191, default: '')]
    public string $method_title = '';

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
                'OrderShipping.order_id must be a 16-byte binary UUIDv7 referencing invflux_orders.id.',
                ['field' => 'order_id'],
            );
        }

        if ('' === $this->external_ref) {
            throw new RecordValidationException(
                'OrderShipping.external_ref must identify the arrangement in the source system.',
                ['field' => 'external_ref'],
            );
        }
    }
}
