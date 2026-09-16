<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Domain\Order;

use Nandan108\Attrecord\Attribute\Column;
use Nandan108\Attrecord\Attribute\Table;
use Nandan108\Attrecord\Attribute\UniqueKey;
use Nandan108\Attrecord\Enum\ColumnType;
use Nandan108\Attrecord\Exception\RecordValidationException;
use Nandan108\Attrecord\Record;

/**
 * One row of the seeded order-correction-type registry.
 *
 * `code` is the stable identifier (`cancel_system`, `writeoff_defective`,
 * `return_resaleable`, …). The three boolean flags drive runtime behaviour:
 * `pre_dispatch × restock` form the 2×2 that selects the slot-movement rule;
 * `refund` is the default-refund-owed flag.
 *
 * **Fault attribution is not stored here.** It derives from `(reason.cause,
 * type direction, store policy)` at the application layer — see {@see Cause}
 * and arch-order-events.
 *
 * Canonical built-in set: {@see BuiltInCorrectionTypes::all()}. Storage adapters
 * seed `invflux_order_correction_types` from that list at install time.
 *
 * @api
 *
 * @psalm-suppress PossiblyUnusedProperty Properties are hydrated by attrecord from row data.
 */
#[Table(name: 'invflux_order_correction_types')]
final class OrderCorrectionType extends Record
{
    #[Column(ColumnType::TinyIntUnsigned, autoIncrement: true)]
    public ?int $id = null;

    #[Column(ColumnType::VarChar, length: 40)]
    #[UniqueKey('uniq_code')]
    public string $code = '';

    #[Column(ColumnType::VarChar, length: 96)]
    public string $name = '';

    #[Column(ColumnType::Bool, default: false)]
    public bool $pre_dispatch = false;

    #[Column(ColumnType::Bool, default: false)]
    public bool $restock = false;

    #[Column(ColumnType::Bool, default: false)]
    public bool $refund = false;

    /**
     * Default refund-composition policy for shipping fees on this
     * correction type. Read by the Pro {@code <ProcessCorrectionsModal>}'s
     * structured-breakdown view; ignored at Essentials where the refund is a
     * single operator-typed total (see
     *  §3).
     *
     * Values:
     * - `null` — undecided; the Pro UI falls back to the merchant's
     *   per-gateway / global default, or to "items only" if neither is set.
     * - `'none'` — never refund shipping for this type.
     * - `'full'` — refund shipping in full.
     * - `'proportional'` — refund shipping pro-rated by `correction.qty /
     *  line.qty_ordered`.
     *
     * Schema-universal per the InvFlux single-plugin tiering principle: the
     * column exists at every tier so the Pro upgrade path is a license flip
     * plus a settings visit, not a data migration.
     */
    #[Column(ColumnType::Enum, enumValues: ['none', 'full', 'proportional'], nullable: true)]
    public ?string $default_refund_shipping = null;

    /**
     * Default refund-composition policy for handling / gift-wrap / fee
     * items. Same null-=-undecided semantics as
     * {@see $default_refund_shipping}. Read by Pro; null at Essentials.
     */
    #[Column(ColumnType::Bool, nullable: true)]
    public ?bool $default_refund_handling = null;

    /**
     * Default refund-composition policy for tax components. Tax usually
     * follows the refunded items; a `true` default lets the Pro UI
     * pre-check the tax toggle. Null-=-undecided as above.
     */
    #[Column(ColumnType::Bool, nullable: true)]
    public ?bool $default_refund_tax = null;

    /**
     * Optional GL posting hint consumed by the future GL projector.
     *
     * Free-form JSON; the projector validates content against its own catalog.
     * Suggested keys (all optional strings): `debit_account`, `credit_account`,
     * `tax_register`, `notes`. A reason-level hint
     * ({@see OrderCorrectionReason::$gl_posting_hint}) typically overrides the
     * type-level hint when both are present — the projector decides composition
     * rules.
     *
     * Empty / null today: the GL projector doesn't exist yet. The column is in
     * place so seed maintainers can stamp hints incrementally without a schema
     * migration when the projector lands.
     *
     * @var array<string, mixed>|null
     */
    #[Column(ColumnType::Json, nullable: true)]
    public ?array $gl_posting_hint = null;

    #[\Override]
    public function validate(): void
    {
        if ('' === $this->code) {
            throw new RecordValidationException(
                'OrderCorrectionType.code must be a non-empty string.',
                ['field' => 'code'],
            );
        }
        if ('' === $this->name) {
            throw new RecordValidationException(
                'OrderCorrectionType.name must be a non-empty string.',
                ['field' => 'name', 'code' => $this->code],
            );
        }
    }
}
