<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Domain\Order;

use Nandan108\Attrecord\Attribute\Column;
use Nandan108\Attrecord\Attribute\Index;
use Nandan108\Attrecord\Attribute\LockTier;
use Nandan108\Attrecord\Attribute\Relation;
use Nandan108\Attrecord\Attribute\Table;
use Nandan108\Attrecord\Attribute\UniqueKey;
use Nandan108\Attrecord\Caster\EnumCaster;
use Nandan108\Attrecord\Enum\ColumnType;
use Nandan108\Attrecord\Enum\ForeignKeyAction;
use Nandan108\Attrecord\Enum\GeneratedColumnMode;
use Nandan108\Attrecord\Enum\RelationType;
use Nandan108\Attrecord\Exception\RecordValidationException;
use Nandan108\Attrecord\Record;
use Nandan108\InvFlux\Domain\Shipment\CostBasis;
use Nandan108\InvFlux\Identity\RecordIdentity;

/**
 * One line on an order. Projected for **every** order line regardless of governance — a line whose
 * subject InvFlux doesn't govern is present but stock-unvouched, never absent (stock movements simply
 * skip it). Governance is a property of the line's subject (`invflux_subjects.ivfx_governed`), not a
 * column here.
 *
 * `external_line_ref` is the source-system identifier (e.g., WC `order_item_id`);
 * UNIQUE per `order_id` to prevent duplicate projection of the same line.
 *
 * Product identity goes through `subject_id` (FK to `invflux_subjects.id`).
 * Source-system product identifiers (WC `post_id`, etc.) are reachable via the
 * `invflux_subject_identifiers` join when the adapter needs them; they are
 * deliberately not denormalized here.
 *
 * `name`, `sku`, `gtin`, `image_url` are **snapshots** taken at order time so the
 * dispatch view stays stable even if the product is later renamed or deleted.
 *
 * @api
 *
 * @psalm-suppress PossiblyUnusedProperty Properties are hydrated by attrecord from row data.
 */
#[Table(name: 'invflux_order_lines')]
#[LockTier(21)]
final class OrderLine extends Record
{
    /** 16-byte binary UUIDv7 — minted on save via RecordIdentity. */
    #[Column(ColumnType::Binary, length: 16)]
    public ?string $id = null;

    /** 16-byte binary UUIDv7 FK to invflux_orders.id. */
    #[Column(ColumnType::Binary, length: 16)]
    #[UniqueKey('uniq_order_line')]
    public ?string $order_id = null;

    #[Column(ColumnType::VarChar, length: 64)]
    #[UniqueKey('uniq_order_line')]
    public string $external_line_ref = '';

    #[Column(ColumnType::IntUnsigned)]
    #[Index('idx_subject')]
    public int $subject_id = 0;

    #[Column(ColumnType::VarChar, length: 191, default: '')]
    public string $name = '';

    #[Column(ColumnType::VarChar, length: 96, default: '')]
    public string $sku = '';

    #[Column(ColumnType::VarChar, length: 32, nullable: true)]
    public ?string $gtin = null;

    #[Column(ColumnType::VarChar, length: 500, nullable: true)]
    public ?string $image_url = null;

    /**
     * The line's shipping class, snapshotted at projection time — a host-native grouping that says
     * *how this item must travel*, not what it is. A packer needs it before they pack: a class
     * carrying a packaging obligation (fragile, hazardous, a contracted service for bottles) is a
     * fact about the parcel, and discovering it at the label printer is discovering it too late.
     *
     * **`null` means the line carries no class, and that is a fact rather than a gap.** An order
     * with one classed line in four is genuinely mixed, and a display that shows only the class
     * would assert every line shares it. Absence is therefore a member of the set wherever these
     * are summarised, never an empty cell.
     *
     * A snapshot, like {@see $sku} and {@see $name} beside it: reclassifying a product does not
     * rewrite how orders already placed had to ship.
     *
     * The *policy* layer over this — whether a class may share a parcel with another — is not here
     * and not in the base install at all. This column is the observation; composition rules are
     * governance and belong to an add-on.
     */
    #[Column(ColumnType::VarChar, length: 96, nullable: true)]
    public ?string $shipping_class = null;

    /**
     * Unit price snapshot taken at order time, in the order's currency — the price **before** any
     * discount, which is {@see $line_discount}. Stored as a decimal string (matching
     * `OrderCorrection.refund_amount` convention) so totals can be computed without float
     * arithmetic.
     *
     * Never the refund basis on its own: a discounted line was not paid at this price, and refunding
     * it asks the host to return money it never took. {@see refundFor()} is the refund basis.
     */
    #[Column(ColumnType::Decimal, precision: 10, scale: 2, default: '0.00')]
    public string $unit_price = '0.00';

    /**
     * The discount taken off this line as a whole, in the order's currency — coupons and manual
     * discounts alike, whatever the host deducted between the line's list total and what it charged.
     * `0.00` when there is none, which is most lines.
     *
     * **Whole-line, not per unit**, because that is how a discount is applied: 10.00 off three
     * units is not a per-unit price at two decimals, and storing one would lose the cent the three
     * of them add up to. What a unit was paid at is derived — {@see netUnitPrice()}.
     *
     * Mirrors the host document, like {@see $qty_ordered}: a coupon applied after the order came in
     * changes it.
     */
    #[Column(ColumnType::Decimal, precision: 10, scale: 2, default: '0.00')]
    public string $line_discount = '0.00';

    /**
     * **Cost at booking** — the subject's effective unit cost (`weighted_avg_cost ?? seed_cost`,
     * base currency) snapshotted at order time, immutable. This is the *expectation* figure — the
     * cost basis believed when the order was placed — distinct from the **authoritative COGS**, which
     * is stamped at dispatch on `ShipmentLine.unit_cost` (the WAC at the moment of outflow). Feeds
     * booked-vs-realized margin analytics (esp. COD/flash-sale, where cancellation/RTO is high) and the
     * ledger's booking-movement cost surface; core-owned so the figure exists for non-WC orders too
     * (WC's `_cogs_value` is a projection of this, never its source). **Never summed as period COGS**
     * — that is `Σ ShipmentLine`. `null` = uncosted (no WAC and no `seed_cost` seed).
     */
    #[Column(ColumnType::Decimal, precision: 10, scale: 4, nullable: true)]
    public ?string $unit_cost = null;

    /** Valuation method that produced {@see $unit_cost}: `wac` (Essentials) or `fifo` (Pro). Null when uncosted. ENUM value list derived from the caster's enum. */
    #[Column(ColumnType::Enum, nullable: true)]
    #[EnumCaster(CostBasis::class)]
    public ?CostBasis $cost_basis = null;

    /** ISO-4217 store-base currency of {@see $unit_cost} (cost is store-base, unlike order-currency `unit_price`). */
    #[Column(ColumnType::Char, length: 3, nullable: true)]
    public ?string $cost_currency = null;

    #[Column(ColumnType::SmallIntUnsigned, default: 0)]
    public int $qty_ordered = 0;

    #[Column(ColumnType::SmallIntUnsigned, default: 0)]
    public int $qty_corrected = 0;

    #[Column(ColumnType::SmallIntUnsigned, default: 0)]
    public int $qty_shipped = 0;

    /**
     * Outstanding customer obligation: how many units on this line still
     * need to be dispatched to honour the order. Maintained by the DB so
     * callers never have to repeat the arithmetic.
     *
     * The operands are CAST to SIGNED before subtracting, exactly as
     * {@see \Nandan108\InvFlux\Domain\Procurement\PurchaseOrderLine::$qty_open}
     * does: all three columns are `SMALLINT UNSIGNED`, so an over-count
     * (`qty_corrected + qty_shipped > qty_ordered`) underflows unsigned
     * arithmetic. `GREATEST(0, …)` clamps the *result*, but the intermediate
     * subtraction range-errors before the clamp is reached — and it does so
     * only when the engine re-evaluates the column across every row, i.e.
     * during a table rebuild such as `ADD CONSTRAINT … FOREIGN KEY`. Reads
     * stay silent, so an unsigned expression here leaves the table readable
     * but permanently un-ALTERable, with the error naming whichever
     * constraint happened to trigger the rebuild. Signing the operands keeps
     * it well-defined at every stage.
     *
     * **`qty_outstanding`, not `qty_shippable`** — the value represents
     * what's still *owed* to the customer, regardless of whether the
     * inventory state can cover it. The deficit case (`sum(qty_outstanding)
     * > ctd_qty` across all orders for a subject) is exactly when "owed"
     * exceeds "can ship", and that's the signal `BIT_STOCK_DEFICIT` on
     * `invflux_subject_stock_concerns` surfaces — see the dispatch
     * "Stock concerns model" design doc.
     *
     * @psalm-suppress UnusedProperty hydrated by the DB on every read;
     *                                no PHP write path touches it.
     */
    #[Column(
        ColumnType::SmallIntUnsigned,
        generatedAs: 'GREATEST(0, CAST(qty_ordered AS SIGNED) - CAST(qty_corrected AS SIGNED) - CAST(qty_shipped AS SIGNED))',
        generatedMode: GeneratedColumnMode::Virtual,
    )]
    public int $qty_outstanding = 0;

    /**
     * Units picked and set aside for this line, not yet shipped.
     *
     * Named for the family it belongs to: the quantities on this line all read `qty_*`, and the
     * `staged_by` / `staged_at` / `staged_source` trio beneath keeps its own prefix, being
     * provenance rather than counts.
     */
    #[Column(ColumnType::SmallIntUnsigned, default: 0)]
    public int $qty_staged = 0;

    #[Column(ColumnType::IntUnsigned, nullable: true)]
    public ?int $staged_by = null;

    #[Column(ColumnType::DateTime, nullable: true, precision: 6)]
    public ?\DateTimeImmutable $staged_at = null;

    #[Column(ColumnType::VarChar, length: 16, nullable: true)]
    public ?string $staged_source = null;

    // Subject-tied concern bits are not cached per line: read paths
    // LEFT JOIN `invflux_subject_stock_concerns` on `subject_id` and
    // read `COALESCE(c.bits, 0)`.

    #[Column(ColumnType::BigIntUnsigned, nullable: true)]
    #[Index('idx_po')]
    public ?int $po_id = null;

    #[Relation(
        RelationType::ManyToOne,
        class: Order::class,
        foreignKey: 'order_id',
        onDelete: ForeignKeyAction::Cascade,
    )]
    public ?Order $order = null;

    /**
     * What one unit of this line was paid at — the list price less its share of the line's
     * discount — as a 2-decimal string. For display; a refund is {@see refundFor()}, which does not
     * round unit by unit.
     */
    public function netUnitPrice(): string
    {
        if ($this->qty_ordered <= 0) {
            return self::fromCents(self::toCents($this->unit_price));
        }

        return self::fromCents((int) round($this->netTotalCents() / $this->qty_ordered));
    }

    /**
     * The refund owed for correcting `$qty` more units of this line, given the
     * {@see $qty_corrected} it already carries — at the price the customer paid, never the list
     * price.
     *
     * Prorated **cumulatively**: the first `n` corrected units carry `round(net × n / ordered)`, and
     * one correction gets the difference between the running totals either side of it. However the
     * line is corrected — all at once or a unit at a time — the pieces add up to exactly its net
     * total. Rounding each unit's share on its own does not: 100.01 over three units rounds to 33.34,
     * and three of those refund 100.02 against a line that charged 100.01.
     *
     * The net total is `unit_price × qty_ordered − line_discount`, so it inherits the list price's
     * own two-decimal rounding: on a line whose list total does not divide by its quantity it can sit
     * a cent either side of what the host charged.
     */
    public function refundFor(int $qty): string
    {
        $ordered = $this->qty_ordered;
        if ($ordered <= 0 || $qty <= 0) {
            return '0.00';
        }

        $before = min(max(0, $this->qty_corrected), $ordered);
        $after = min($before + $qty, $ordered);
        $net = $this->netTotalCents();

        return self::fromCents(self::share($net, $after, $ordered) - self::share($net, $before, $ordered));
    }

    /** The whole line at the price paid, in cents; never negative. */
    private function netTotalCents(): int
    {
        return max(0, self::toCents($this->unit_price) * $this->qty_ordered - self::toCents($this->line_discount));
    }

    /** The part of `$net` that the first `$units` of `$ordered` carry. */
    private static function share(int $net, int $units, int $ordered): int
    {
        return (int) round($net * $units / $ordered);
    }

    private static function toCents(string $amount): int
    {
        return (int) round((float) $amount * 100.0);
    }

    private static function fromCents(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
    }

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
                'OrderLine.order_id must be a 16-byte binary UUIDv7 referencing invflux_orders.id.',
                ['field' => 'order_id'],
            );
        }
        if ('' === $this->external_line_ref) {
            throw new RecordValidationException(
                'OrderLine.external_line_ref must be a non-empty string.',
                ['field' => 'external_line_ref'],
            );
        }
        if ($this->subject_id <= 0) {
            throw new RecordValidationException(
                'OrderLine.subject_id must be a positive integer.',
                ['field' => 'subject_id'],
            );
        }
    }
}
