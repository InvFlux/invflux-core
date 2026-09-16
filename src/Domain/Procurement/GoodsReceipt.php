<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Domain\Procurement;

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
use Nandan108\InvFlux\Identity\RefTypeRecord;

/**
 * A goods-receipt event — one arrival of stock, and the line detail of what arrived lives on
 * {@see ReceiptLine}.
 *
 * **What it arrived against is polymorphic.** Most receipts answer a {@see PurchaseOrder} (which can
 * have N of them), but the supplier lifecycle is not the only way stock legitimately turns up: an
 * opening balance, a delivery nobody raised an order for, goods made in-house, a transfer from
 * another of our locations. Those are *receipts*, not corrections — they carry a real cost layer with
 * a date and a provenance, which is exactly what an on-hand correction does not have and must not be
 * misused to fake.
 *
 * So the source is {@see $source_ref_type_id} + {@see $source_id}: the same registered-ref-type
 * shape the ledger and {@see PoEvent} already use, rather than a hard foreign key to one table. Two
 * reasons, and the second is the load-bearing one:
 *
 * - the referent differs by kind, so no single FK can express it; and
 * - **the set of kinds is open.** An advance-shipping-notice capability contributes its own source
 *   by registering a ref type, and this class never learns the word. A closed enum here would make
 *   the base name a document it does not ship — the same reason slot states are registered values
 *   rather than a constant class.
 *
 * **No source at all is a source-less intake**, and then {@see $reason} is what makes the row
 * well-formed: it says why the stock is here and, through {@see ReceiptReason::costSource()}, what
 * the intake owes about cost.
 *
 * @api
 *
 * @psalm-suppress PossiblyUnusedProperty Properties are hydrated by attrecord from row data.
 */
#[Table(name: 'invflux_goods_receipts')]
#[LockTier(35)]
final class GoodsReceipt extends Record
{
    #[Column(ColumnType::IntUnsigned, autoIncrement: true)]
    public ?int $id = null;

    /**
     * Which *kind* of document this delivery answers — a registered ref type, resolved through
     * {@see RefTypeRecord}. NULL means no document ordered these goods: a source-less intake, which
     * {@see $reason} then has to account for.
     */
    #[Column(ColumnType::SmallIntUnsigned, nullable: true)]
    #[Index('idx_source')]
    public ?int $source_ref_type_id = null;

    /**
     * The referenced document's id, discriminated by {@see $source_ref_type_id} — no hard foreign
     * key, because the target table varies by kind. NULL exactly when the ref type is.
     *
     * `renamedFrom` is what makes the generalisation data-preserving on an existing install: this
     * column *is* the old `po_id`, widened in meaning rather than replaced, so a converge renames it
     * and keeps every row's value instead of adding a column beside a doomed one. The discriminator
     * cannot be inferred that way and is backfilled by a companion data step.
     */
    #[Column(ColumnType::IntUnsigned, nullable: true, renamedFrom: 'po_id')]
    #[Index('idx_source')]
    public ?int $source_id = null;

    /**
     * Why stock arrived with no document behind it, and thereby what it must state about cost.
     * Required on a source-less intake, refused on any other — a receipt against an order takes its
     * cost from that order.
     */
    #[Column(ColumnType::Enum, nullable: true)]
    #[EnumCaster(ReceiptReason::class)]
    public ?ReceiptReason $reason = null;

    /**
     * Who delivered, where that is a fact worth keeping and no document already records it — a
     * supplier delivery raised against no order is the case that needs it. Reached through the
     * purchase order otherwise, and absent for an opening balance or an in-house build, which come
     * from nobody.
     *
     * Operational provenance, for claims and reporting. It is **not** a party snapshot: what a
     * *document* stated about a party is {@see DocumentParty}'s job, frozen at issue, and a receipt
     * note that became an issued document would carry those pointers as well as this.
     */
    #[Column(ColumnType::IntUnsigned, nullable: true)]
    #[Index('idx_supplier')]
    public ?int $supplier_id = null;

    #[Column(ColumnType::DateTime, precision: 6)]
    public ?\DateTimeImmutable $received_at = null;

    #[Column(ColumnType::IntUnsigned, nullable: true)]
    public ?int $received_by = null;

    #[Column(ColumnType::VarChar, length: 500, nullable: true)]
    public ?string $note = null;

    /**
     * The captured (snapshot) FX rate that converted this delivery's line costs into the store-base
     * currency — units of base per 1 supplier-currency unit. Captured at "Confirm receipt" and applied
     * at the WAC boundary (`unit_cost_snapshot × fx_rate → base`) in {@see \Nandan108\InvFlux\Application\Procurement\ReceiveGoods}.
     * The FX rate is intrinsically a receipt-time fact ("the rate at which THIS delivery capitalized into
     * stock"), so its home is the receipt, not the PO — there is no PO-level rate. Null ⇒ 1.0 (the PO
     * currency equals the store base; the trivial identity case).
     */
    #[Column(ColumnType::Decimal, precision: 18, scale: 8, nullable: true)]
    public ?string $fx_rate = null;

    #[Column(ColumnType::DateTime, precision: 6)]
    public ?\DateTimeImmutable $created_at = null;

    /**
     * The ref type itself, when one is set — the registry row that says what {@see $source_id}
     * points at. Restrict rather than cascade: deleting a *kind* of document out from under the
     * receipts that name it would leave them unable to say what they answered.
     */
    #[Relation(
        RelationType::ManyToOne,
        class: RefTypeRecord::class,
        foreignKey: 'source_ref_type_id',
        onDelete: ForeignKeyAction::Restrict,
    )]
    public ?RefTypeRecord $sourceRefType = null;

    /**
     * The supplier who delivered, when one is recorded — see {@see $supplier_id} for when that is.
     * Restrict, because a supplier with deliveries on file is not deletable without deciding what
     * those deliveries then say.
     */
    #[Relation(
        RelationType::ManyToOne,
        class: Supplier::class,
        foreignKey: 'supplier_id',
        onDelete: ForeignKeyAction::Restrict,
    )]
    public ?Supplier $supplier = null;

    /** Whether this delivery answers a document at all; false for a source-less intake. */
    public function hasSource(): bool
    {
        return null !== $this->source_ref_type_id;
    }

    #[\Override]
    public function beforeSave(): void
    {
        $now = new \DateTimeImmutable();
        if (null === $this->created_at) {
            $this->created_at = $now;
        }
        if (null === $this->received_at) {
            $this->received_at = $now;
        }
    }

    /**
     * A receipt is well-formed in one of two ways, and the check is that it is not somewhere in
     * between: it either **answers a document** — both halves of the ref present, no reason, because
     * the document already says why — or it is a **source-less intake**, with neither half and a
     * reason that accounts for it.
     *
     * Half a reference is the shape worth refusing loudly. A ref type with no id points at nothing;
     * an id with no ref type points at everything.
     */
    #[\Override]
    public function validate(): void
    {
        $hasType = null !== $this->source_ref_type_id;
        $hasId = null !== $this->source_id;

        if ($hasType !== $hasId) {
            throw new RecordValidationException(
                'GoodsReceipt source is half-set: source_ref_type_id and source_id must both be present, or both absent.',
                ['field' => 'source_id', 'source_ref_type_id' => $this->source_ref_type_id, 'source_id' => $this->source_id],
            );
        }

        if ($hasType) {
            if ($this->source_ref_type_id <= 0 || $this->source_id <= 0) {
                throw new RecordValidationException(
                    'GoodsReceipt.source_ref_type_id and source_id must be positive integers when set.',
                    ['field' => 'source_id', 'source_ref_type_id' => $this->source_ref_type_id, 'source_id' => $this->source_id],
                );
            }
            if (null !== $this->reason) {
                throw new RecordValidationException(
                    'GoodsReceipt.reason belongs to a source-less intake; a receipt against a document takes its cost from that document.',
                    ['field' => 'reason', 'value' => $this->reason->value],
                );
            }

            return;
        }

        if (null === $this->reason) {
            throw new RecordValidationException(
                'GoodsReceipt with no source must carry a reason — it is what says why the stock is here and what it owes about cost.',
                ['field' => 'reason', 'value' => null],
            );
        }
    }
}
