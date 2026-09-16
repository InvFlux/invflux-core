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

/**
 * One billed line of a {@see SupplierInvoice} — how many units of an ordered line, at what rate.
 *
 * **Against a purchase-order line, and only that.** A supplier billing something never ordered is a
 * real event, but it is a dispute rather than a line we can value against: there is no ordered line
 * to attach a rate to and no receipt that will ever consume it. Recording it belongs to the Pro
 * match, which has somewhere to put an unmatched charge; refusing it here keeps this document
 * honestly narrow rather than half-modelling it.
 *
 * Quantity and rate are both the supplier's claim, kept verbatim. They are **not** reconciled
 * against what arrived — that comparison is the three-way match, and it is a Pro reading of these
 * facts rather than a condition of recording them. Ship ten and invoice twelve, and both numbers
 * stay exactly as each party stated them.
 *
 * @api
 *
 * @psalm-suppress PossiblyUnusedProperty Properties are hydrated by attrecord from row data.
 */
#[Table(name: 'invflux_supplier_invoice_lines')]
#[LockTier(41)]
final class SupplierInvoiceLine extends Record
{
    #[Column(ColumnType::IntUnsigned, autoIncrement: true)]
    public ?int $id = null;

    #[Column(ColumnType::IntUnsigned)]
    #[Index('idx_invoice')]
    public int $invoice_id = 0;

    #[Relation(
        RelationType::ManyToOne,
        class: SupplierInvoice::class,
        foreignKey: 'invoice_id',
        onDelete: ForeignKeyAction::Cascade,
    )]
    public ?SupplierInvoice $invoice = null;

    /**
     * The ordered line being billed. Restrict, not Cascade: deleting an order line that a supplier
     * has already invoiced would leave the charge unexplained, so the deletion is the thing that
     * must be refused.
     */
    #[Column(ColumnType::IntUnsigned)]
    #[Index('idx_po_line')]
    public int $po_line_id = 0;

    #[Relation(
        RelationType::ManyToOne,
        class: PurchaseOrderLine::class,
        foreignKey: 'po_line_id',
        onDelete: ForeignKeyAction::Restrict,
    )]
    public ?PurchaseOrderLine $purchaseOrderLine = null;

    /** Units billed on this line, as the supplier stated them. */
    #[Column(ColumnType::IntUnsigned)]
    public int $qty = 0;

    /** Rate billed per unit, in the invoice's currency — the net, after any line discount. */
    #[Column(ColumnType::Decimal, precision: 10, scale: 4)]
    public string $unit_cost = '0.0000';

    /**
     * The price before the supplier's line discount, when the invoice states one. Set only together
     * with {@see $discount_pct}; {@see $unit_cost} is then the net {@see LinePrice} computes from them.
     */
    #[Column(ColumnType::Decimal, precision: 10, scale: 4, nullable: true)]
    public ?string $list_unit_cost = null;

    /** The supplier's percentage discount off {@see $list_unit_cost}, 0 up to (not including) 100. */
    #[Column(ColumnType::Decimal, precision: 5, scale: 2, nullable: true)]
    public ?string $discount_pct = null;

    /** Store a price reached through {@see LinePrice}. An invoice line always bills at a price. */
    public function applyPrice(LinePrice $price): void
    {
        if (null === $price->net) {
            throw new RecordValidationException('An invoice line bills at a stated rate.');
        }
        $this->unit_cost = $price->net;
        $this->list_unit_cost = $price->list;
        $this->discount_pct = $price->discountPct;
    }

    #[\Override]
    public function validate(): void
    {
        if ($this->qty < 1) {
            throw new RecordValidationException('An invoice line bills at least one unit.');
        }
        if ((float) $this->unit_cost < 0) {
            throw new RecordValidationException('An invoice line cannot bill a negative rate.');
        }
        if (!LinePrice::isCoherent($this->unit_cost, $this->list_unit_cost, $this->discount_pct)) {
            throw new RecordValidationException(
                'An invoice line\'s rate must be the net of its list price less its discount, and both or neither must be set.',
            );
        }
    }
}
