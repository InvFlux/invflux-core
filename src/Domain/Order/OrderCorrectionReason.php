<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Domain\Order;

use Nandan108\Attrecord\Attribute\Column;
use Nandan108\Attrecord\Attribute\Index;
use Nandan108\Attrecord\Attribute\Table;
use Nandan108\Attrecord\Attribute\UniqueKey;
use Nandan108\Attrecord\Caster\EnumCaster;
use Nandan108\Attrecord\Enum\ColumnType;
use Nandan108\Attrecord\Exception\RecordValidationException;
use Nandan108\Attrecord\Record;

/**
 * One row of the order-correction-reason registry.
 *
 * Reasons answer **why** a correction happened — orthogonal to
 * {@see OrderCorrectionType} which answers **what shape** it takes. The `cause`
 * ENUM carries the categorical origin (customer / merchant / logistics) for
 * reporting; merchant liability itself is derived at the application layer,
 * not stored.
 *
 * Canonical built-in set: {@see BuiltInCorrectionReasons::all()}.
 *
 * @api
 *
 * @psalm-suppress PossiblyUnusedProperty Properties are hydrated by attrecord from row data.
 */
#[Table(name: 'invflux_order_correction_reasons')]
final class OrderCorrectionReason extends Record
{
    #[Column(ColumnType::TinyIntUnsigned, autoIncrement: true)]
    public ?int $id = null;

    #[Column(ColumnType::VarChar, length: 40)]
    #[UniqueKey('uniq_code')]
    public string $code = '';

    #[Column(ColumnType::VarChar, length: 96)]
    public string $name = '';

    /** Categorical origin of the correction (see {@see Cause}). ENUM value list derived from the caster's enum. */
    #[Column(ColumnType::Enum)]
    #[EnumCaster(Cause::class)]
    #[Index('idx_cause')]
    public Cause $cause = Cause::Customer;

    /**
     * When the reason can apply relative to shipment (see {@see CorrectionTiming}). Narrows the reasons
     * offered for a correction, and refuses one whose timing contradicts the correction's type.
     */
    #[Column(ColumnType::Enum, default: CorrectionTiming::Any)]
    #[EnumCaster(CorrectionTiming::class)]
    public CorrectionTiming $timing = CorrectionTiming::Any;

    /**
     * Optional GL posting hint consumed by the future GL projector.
     *
     * Free-form JSON; same shape as {@see OrderCorrectionType::$gl_posting_hint}
     * (suggested keys: `debit_account`, `credit_account`, `tax_register`,
     * `notes`). When both a reason-level and type-level hint are present, the
     * projector typically lets the reason override the type — the reason is
     * the more specific "why" and so warrants the more specific posting.
     *
     * Empty / null today; the GL projector doesn't exist yet.
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
                'OrderCorrectionReason.code must be a non-empty string.',
                ['field' => 'code'],
            );
        }
        if ('' === $this->name) {
            throw new RecordValidationException(
                'OrderCorrectionReason.name must be a non-empty string.',
                ['field' => 'name', 'code' => $this->code],
            );
        }
        // $cause is enum-typed (EnumCaster) — the type system guarantees a valid Cause.
    }
}
