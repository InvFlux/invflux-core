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
 * A supplier's invoice against a purchase order — what we were charged, as they stated it.
 *
 * **A document family of its own, and that is how ERPs model it.** SAP keeps purchasing
 * (`EKKO`/`EKPO`), the material document a goods receipt writes (`MKPF`/`MSEG`) and invoice
 * verification (`RBKP`/`RSEG`) as three separate families; the three-way match *is* the join between
 * them. One order attracting several invoices is the ordinary case there, not an edge — a part
 * delivery invoiced on arrival and the rest invoiced a month later is two documents about one order.
 * Columns on the order could not hold that.
 *
 * **It records, it does not post.** Financial facts are a projection of operational ones and never
 * drive them: this moves no stock, changes no purchase-order status, opens no payable and clears
 * nothing. It is a recorded statement feeding valuation and, at Pro, variance. The moment it becomes
 * a payable it is the ledger's job, reached by *emitting* outward through an accounting adapter —
 * never by an invoice writing back into InvFlux state.
 *
 * **What it changes is two fields on the ordered lines**, maintained by
 * {@see \Nandan108\InvFlux\Application\Procurement\RecordSupplierInvoice}: `qty_invoiced` accumulates
 * (how many units have we been billed for) and `unit_cost_invoiced` takes the latest rate (what does
 * a unit cost now). Only the second is an input to valuation, and only for receipts posted after it.
 *
 * @api
 *
 * @psalm-suppress PossiblyUnusedProperty Properties are hydrated by attrecord from row data.
 */
#[Table(name: 'invflux_supplier_invoices')]
#[LockTier(40)]
final class SupplierInvoice extends Record
{
    #[Column(ColumnType::IntUnsigned, autoIncrement: true)]
    public ?int $id = null;

    /**
     * The order this invoice bills. Cascade: an invoice is *about* an order and has no meaning
     * without it — unlike a goods receipt, which records a physical arrival that happened whatever
     * became of the paperwork.
     */
    #[Column(ColumnType::IntUnsigned)]
    #[Index('idx_po')]
    public int $po_id = 0;

    #[Relation(
        RelationType::ManyToOne,
        class: PurchaseOrder::class,
        foreignKey: 'po_id',
        onDelete: ForeignKeyAction::Cascade,
    )]
    public ?PurchaseOrder $purchaseOrder = null;

    /**
     * The supplier's own invoice number, as printed. Not ours and not unique here: two suppliers can
     * legitimately issue the same number, and a supplier may re-issue a corrected one under it.
     */
    #[Column(ColumnType::VarChar, length: 64)]
    public string $reference = '';

    /** The date the supplier dated it — theirs, not when we typed it in. */
    #[Column(ColumnType::Date, nullable: true)]
    public ?\DateTimeImmutable $invoiced_at = null;

    /** When we recorded it. A recorded fact whose ordering matters, so full precision. */
    #[Column(ColumnType::DateTime, precision: 6, nullable: true)]
    public ?\DateTimeImmutable $recorded_at = null;

    #[Column(ColumnType::IntUnsigned, nullable: true)]
    public ?int $recorded_by = null;

    /**
     * The invoice's own currency, which is the order's in every ordinary case. Stored rather than
     * read back through the order because an invoice is a statement made at a moment: re-reading the
     * order's currency later would silently restate what the supplier actually billed.
     */
    #[Column(ColumnType::VarChar, length: 3)]
    public string $currency = '';

    /**
     * The document's stated total, when the operator enters one — kept as the supplier wrote it,
     * never derived from the lines.
     *
     * It is deliberately **not** a computed sum: a real invoice's total includes freight, duty,
     * surcharges and rounding that no goods line carries, so a total assembled from lines would
     * disagree with the paper and look like our arithmetic was wrong. Holding the stated figure is
     * also what lets a Pro match notice that lines and total *do not* reconcile, which is a finding
     * rather than an error.
     */
    #[Column(ColumnType::Decimal, precision: 12, scale: 4, nullable: true)]
    public ?string $stated_total = null;

    #[Column(ColumnType::VarChar, length: 500, nullable: true)]
    public ?string $note = null;

    #[\Override]
    public function validate(): void
    {
        if ('' === trim($this->reference)) {
            throw new RecordValidationException('A supplier invoice needs the number the supplier printed on it.');
        }
        if (3 !== \strlen($this->currency)) {
            throw new RecordValidationException('A supplier invoice needs a three-letter currency.');
        }
    }
}
