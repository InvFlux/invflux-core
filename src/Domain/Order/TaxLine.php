<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Domain\Order;

use Nandan108\Attrecord\Attribute\Column;
use Nandan108\Attrecord\Attribute\Index;
use Nandan108\Attrecord\Attribute\LockTier;
use Nandan108\Attrecord\Attribute\Table;
use Nandan108\Attrecord\Enum\ColumnType;
use Nandan108\Attrecord\Exception\RecordValidationException;
use Nandan108\Attrecord\Record;
use Nandan108\InvFlux\Identity\RecordIdentity;

/**
 * One tax-rate line attached to an entity that participates in money flow on an
 * order — snapshotted at the moment that entity captures money.
 *
 * Tax is not correction-specific. The same `(tax_code, net, tax_rate, tax_amount)`
 * shape applies to anything that captures money on an order:
 *
 * - {@see OrderLine} (future, **load-bearing**) — original sale tax. Today the
 *   Order projection doesn't carry tax; will get TaxLines stamped at projection
 *   time. WC edits/deletes can change the upstream line, so the snapshot is the
 *   only durable record of what tax was true at sale time. This is where the
 *   audit-grade goal is real.
 * - Future: Shipment (delivered-with-tax, when shipments become their own
 *   Record class), PurchaseOrder line (input VAT on goods received), supplier
 *   claim line (tax recovery on a claim). Note that **returns are corrections**
 *   (post-shipment `OrderCorrection` types like `return_resaleable`), not a
 *   separate parent — the correction's `type_code` already discriminates.
 * - {@see OrderCorrection} (optional / intent-only) — the snapshot is *largely
 *   redundant* with `correction.refund_confirmed` event payloads that carry what WC
 *   actually refunded. The plumbing accepts it for callers that want a "proof of intent
 *   at correction creation" audit trail, but `LpscCorrectionWriter` does not
 *   populate it today by design.
 *
 * **Polymorphic parent shape.** Single `(parent_type, parent_id)` pair instead
 * of per-parent FK columns. No hard FK — MySQL doesn't natively enforce
 * polymorphic FKs and we've consciously accepted that trade-off for the
 * elegance of one extension point. Adding a new parent class is just
 * registering a new `parent_type` string; no schema migration. Integrity is
 * application-side: callers must ensure the parent exists before stamping a
 * TaxLine against it. Parents in this domain (OrderLine, OrderCorrection,
 * Shipment, …) are append-only in practice; orphaned TaxLines from a deleted
 * parent are a rare-edge concern and surface in audit queries, not in
 * day-to-day operations.
 *
 * @api
 *
 * @psalm-suppress PossiblyUnusedProperty Properties are hydrated by attrecord from row data.
 */
#[Table(name: 'invflux_tax_lines')]
// Above the order family it belongs to rather than beside it: 24 is held by `Shipment`, and two
// Records on one tier have no defined lock order between them. What matters is the order relative
// to what this is locked *with* — after `Order` and `OrderLine`, which it keys into — and that
// holds from anywhere above 21.
#[LockTier(45)]
#[Index('idx_parent', columns: ['parent_type', 'parent_id'])]
final class TaxLine extends Record
{
    /**
     * Known parent_type values. Add to this list as new parent entities arrive.
     *
     * Note: returns are corrections (post-shipment `OrderCorrection` types like
     * `return_resaleable`), not a separate parent — the correction's `type_code`
     * already discriminates. There is no future `'return'` parent_type.
     */
    public const KNOWN_PARENT_TYPES = [
        'correction',
        // Future: 'order_line', 'shipment', 'po_line', 'supplier_claim', ...
    ];

    /** 16-byte binary UUIDv7 — minted on save via RecordIdentity. */
    #[Column(ColumnType::Binary, length: 16)]
    public ?string $id = null;

    /**
     * Discriminator: which parent entity this row tax-snapshots. Must be in
     * {@see self::KNOWN_PARENT_TYPES}. New parents register by extending that list
     * — no schema migration.
     */
    #[Column(ColumnType::VarChar, length: 32)]
    public string $parent_type = '';

    /**
     * BINARY(16) UUIDv7 referencing the parent entity. Not a hard FK — MySQL
     * doesn't natively enforce polymorphic FKs. Application-side write paths
     * are responsible for ensuring the parent exists before stamping.
     */
    #[Column(ColumnType::Binary, length: 16)]
    public ?string $parent_id = null;

    /**
     * Source-system tax-rate identifier (in WooCommerce, the `tax_rate_id`
     * referenced by `WC_Order_Item::get_taxes()`). Kept as VARCHAR so non-Woo
     * adapters (Shopify tax line id, manual code, etc.) can use it without
     * forcing INT semantics.
     */
    #[Column(ColumnType::VarChar, length: 64)]
    public string $tax_code = '';

    /** Pre-tax amount (net) on this rate share. DECIMAL(10,2) as string. */
    #[Column(ColumnType::Decimal, precision: 10, scale: 2, default: '0.00')]
    public string $net = '0.00';

    /**
     * Tax rate as a percentage with up to 4 decimal places (e.g. "19.0000",
     * "8.2500"). 5 integer digits + 4 fractional covers any sane jurisdiction.
     */
    #[Column(ColumnType::Decimal, precision: 9, scale: 4, default: '0.0000')]
    public string $tax_rate = '0.0000';

    /** Tax amount on this rate share. DECIMAL(10,2) as string. */
    #[Column(ColumnType::Decimal, precision: 10, scale: 2, default: '0.00')]
    public string $tax_amount = '0.00';

    #[Column(ColumnType::DateTime, nullable: true, precision: 6)]
    public ?\DateTimeImmutable $created_at = null;

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
        if (!\in_array($this->parent_type, self::KNOWN_PARENT_TYPES, true)) {
            throw new RecordValidationException(
                sprintf(
                    'TaxLine.parent_type "%s" is not in KNOWN_PARENT_TYPES; valid: %s.',
                    $this->parent_type,
                    implode(', ', self::KNOWN_PARENT_TYPES),
                ),
                ['field' => 'parent_type', 'value' => $this->parent_type],
            );
        }
        if (null === $this->parent_id || 16 !== \strlen($this->parent_id)) {
            throw new RecordValidationException(
                'TaxLine.parent_id must be a 16-byte binary UUIDv7.',
                ['field' => 'parent_id'],
            );
        }
        if ('' === $this->tax_code) {
            throw new RecordValidationException(
                'TaxLine.tax_code must be non-empty.',
                ['field' => 'tax_code'],
            );
        }
    }
}
