<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Domain\Order;

use Nandan108\Attrecord\Attribute\Check;
use Nandan108\Attrecord\Attribute\Column;
use Nandan108\Attrecord\Attribute\ForeignKey;
use Nandan108\Attrecord\Attribute\Index;
use Nandan108\Attrecord\Attribute\LockTier;
use Nandan108\Attrecord\Attribute\PrimaryKey;
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
#[PrimaryKey(columns: ['order_id', 'line_id'])]
// A kit component points at the line it was exploded from, within the same order.
//
// CASCADE, and it has to be. RESTRICT here does not merely make component deletion explicit — it
// makes any order carrying a kit **undeletable**: deleting the order cascades into its lines, and
// that cascade then hits the RESTRICT between a kit line and its own components in the very set
// being removed. InnoDB has no deferred constraints, so it refuses the whole statement with
// errno 1451. Measured on a probe carrying this exact constraint shape, not reasoned about —
// note that a `CREATE TABLE … LIKE` copy cannot exhibit it, because LIKE does not copy foreign
// keys. CASCADE deletes the order cleanly, and removing a kit line takes its components with it,
// which is the domain rule anyway.
#[ForeignKey(
    column: ['order_id', 'parent_line_id'],
    references: OrderLine::class,
    onDelete: ForeignKeyAction::Cascade,
)]
// A line must name either the host item it projects or the line it was exploded from. The unique
// key `(order_id, external_line_ref)` cannot say this: component lines have no host counterpart, so
// they carry no ref, and two of them in one order would collide on the same empty string. NULL is
// both the honest value and the one a UNIQUE key admits more than once — and this check is what
// keeps the guarantee the NOT NULL used to give, for every writer including those that never reach
// OrderLine::validate().
#[Check('ref_or_parent', 'external_line_ref IS NOT NULL OR parent_line_id IS NOT NULL')]
#[LockTier(21)]
final class OrderLine extends Record
{
    /**
     * 16-byte binary UUIDv7 FK to `invflux_orders.id`, and the key's leading member.
     *
     * A line has no identity apart from its order — it is the order's second line, not a document
     * in its own right — so it is numbered *within* one rather than carrying a UUID of its own.
     * What makes that affordable is that **the ledger never names an order line**: its two
     * reference lanes are a 16-byte column and an int column, so a document a movement can cite
     * has to keep a 16-byte key. Orders, shipments and corrections do. Lines are cited through
     * their order, and their subject carries the per-product attribution, which is why this one
     * could give its surrogate up.
     */
    #[Column(ColumnType::Binary, length: 16)]
    #[UniqueKey('uniq_order_line')]
    public ?string $order_id = null;

    /**
     * The line's number within its order, allocated from {@see Order::$next_line_no}.
     *
     * **Monotonic per order and never reused**, which is the whole reason the allocator is a
     * column on the order rather than `MAX(line_id) + 1` here. A line that is pruned — its source
     * item removed from the host document, nothing referring to it — frees its number under
     * `MAX + 1`, and a client still holding that number would then address a different product.
     * A high-water mark costs two bytes on the order and makes the confusion impossible.
     *
     * `SMALLINT` rather than `TINYINT` on measurement, not instinct: the busiest order on the
     * development store carries 53 lines, which is close enough to 255 to be a bad bet.
     */
    #[Column(ColumnType::SmallIntUnsigned)]
    public int $line_id = 0;

    /**
     * The line this one was exploded from, when a kit was expanded into its components — numbered
     * in the same order's numbering, so {@see $order_id} serves both halves of the reference.
     *
     * That shared column is the point, not the two bytes: a component line and its parent are
     * named by one `order_id`, so a component of *another order's* kit cannot be represented at
     * all. A surrogate parent id could only be checked, never made impossible.
     *
     * `null` on an ordinary line, and on a kit's own line — a parent has no parent.
     */
    #[Column(ColumnType::SmallIntUnsigned, nullable: true)]
    public ?int $parent_line_id = null;

    /**
     * Which version of the kit's bill of materials was exploded onto this line's components.
     *
     * A **number, deliberately not a foreign key.** An order line is a historical record: it has
     * to keep naming the BOM it was built from after that BOM's header is deleted or its subject
     * retired, and an FK could only offer RESTRICT (blocking the delete to protect the history) or
     * SET NULL (erasing the history to permit it). Both answer the wrong question. `subject_id` is
     * already on this line, so `(subject_id, bom_version)` names the BOM completely under the
     * one-versioned-header-per-subject rule.
     *
     * `null` on any line not produced by a kit explosion.
     */
    #[Column(ColumnType::SmallIntUnsigned, nullable: true)]
    public ?int $bom_version = null;

    /**
     * The source system's identifier for this line (a WooCommerce `order_item_id`), unique within
     * the order so the same host line cannot be projected twice.
     *
     * **`null` means this line has no host counterpart**, which is a fact rather than a gap: a kit
     * component is created by exploding {@see $parent_line_id}, not by a host document naming it.
     * `''` would say the host gave us an empty string, and would collide under the unique key the
     * moment an order carried two components.
     */
    #[Column(ColumnType::VarChar, length: 64, nullable: true)]
    #[UniqueKey('uniq_order_line')]
    public ?string $external_line_ref = null;

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

    /**
     * A stable string for the whole key, for use as an array key when lines are collected into a
     * map — which several callers do, to pair projection rows against document rows.
     *
     * **Keying such a map by the line number alone is wrong**, and wrong in the quiet way: line 3
     * exists on almost every order, so a map keyed by number silently merges lines from different
     * orders and the last one written wins. That could not happen while the key was a UUID, so it
     * is a hazard this change introduces and this method exists to remove.
     *
     * The encoding is the order's hex followed by the number. Its only contract is that equal keys
     * mean the same line and different keys different lines; nothing parses it back.
     */
    public static function keyOf(?string $orderId, int $lineId): string
    {
        return bin2hex($orderId ?? '').':'.$lineId;
    }

    /** This line's {@see keyOf()}. */
    public function key(): string
    {
        return self::keyOf($this->order_id, $this->line_id);
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
        // Both key members, and this one has no null to be caught by: it is a typed int with a 0
        // default, so "never allocated" and "allocated zero" are the same value to attrecord's
        // incomplete-key guard. Writers take it from Order::$next_line_no, which starts at 1.
        if ($this->line_id <= 0) {
            throw new RecordValidationException(
                'OrderLine.line_id must be a positive integer allocated from Order.next_line_no.',
                ['field' => 'line_id', 'value' => $this->line_id],
            );
        }
        // Mirrors `chk_ref_or_parent`, which the database enforces for every writer. Kept here too
        // so a line built in memory fails at the write that made it, not at the constraint.
        if (null === $this->external_line_ref && null === $this->parent_line_id) {
            throw new RecordValidationException(
                'OrderLine.external_line_ref may only be null on a line exploded from a parent '
                .'(parent_line_id set); a line projected from a host document must name it.',
                ['field' => 'external_line_ref'],
            );
        }
        if ('' === $this->external_line_ref) {
            throw new RecordValidationException(
                'OrderLine.external_line_ref must be a non-empty string when set; '
                .'a line with no host counterpart carries null, not an empty string.',
                ['field' => 'external_line_ref'],
            );
        }
        if ($this->subject_id <= 0) {
            throw new RecordValidationException(
                'OrderLine.subject_id must be a positive integer.',
                ['field' => 'subject_id'],
            );
        }
        if (null !== $this->parent_line_id && $this->parent_line_id <= 0) {
            throw new RecordValidationException(
                'OrderLine.parent_line_id must be null or a positive line number within the same order.',
                ['field' => 'parent_line_id', 'value' => $this->parent_line_id],
            );
        }
        // The foreign key cannot catch this one: a row pointing at itself satisfies it, since the
        // referenced row exists — it is this row. A one-line cycle is still not a parentage.
        if (null !== $this->parent_line_id && $this->parent_line_id === $this->line_id) {
            throw new RecordValidationException(
                'OrderLine.parent_line_id must not name the line itself.',
                ['field' => 'parent_line_id', 'value' => $this->parent_line_id],
            );
        }
        if (null !== $this->bom_version && $this->bom_version <= 0) {
            throw new RecordValidationException(
                'OrderLine.bom_version must be null or a positive version number.',
                ['field' => 'bom_version', 'value' => $this->bom_version],
            );
        }
    }
}
