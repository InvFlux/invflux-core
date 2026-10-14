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
 * One receipt line's figures before → after a {@see ReceiptCorrection}: good quantity, damaged
 * quantity, unit cost. Only lines that changed are written.
 *
 * The cost figures mirror the receipt line's own: the unit cost is in the receipt's line currency,
 * and the product-level `on_hand_before` / `cost_before` / `cost_after` are in the store base, as the
 * correction read and left them. When the stock left could not absorb a change of value, the average
 * was kept and {@see $cost_variance} holds the part that did not fit.
 *
 * @api
 *
 * @psalm-suppress PossiblyUnusedProperty Properties are hydrated by attrecord from row data.
 */
#[Table(name: 'invflux_receipt_correction_lines')]
#[LockTier(51)]
final class ReceiptCorrectionLine extends Record
{
    #[Column(ColumnType::IntUnsigned, autoIncrement: true)]
    public ?int $id = null;

    #[Column(ColumnType::IntUnsigned)]
    #[Index('idx_correction')]
    public int $correction_id = 0;

    /** The receipt line corrected. */
    #[Column(ColumnType::IntUnsigned)]
    #[Index('idx_receipt_line')]
    public int $receipt_line_id = 0;

    /** The line's product, carried so a correction reads per product without the receipt line. */
    #[Column(ColumnType::IntUnsigned)]
    public int $subject_id = 0;

    #[Column(ColumnType::IntUnsigned)]
    public int $qty_before = 0;

    #[Column(ColumnType::IntUnsigned)]
    public int $qty_after = 0;

    #[Column(ColumnType::IntUnsigned)]
    public int $damaged_before = 0;

    #[Column(ColumnType::IntUnsigned)]
    public int $damaged_after = 0;

    /** Unit cost in the receipt's line currency; null when the line carried none. */
    #[Column(ColumnType::Decimal, precision: 20, scale: 4, nullable: true)]
    public ?string $unit_cost_before = null;

    #[Column(ColumnType::Decimal, precision: 20, scale: 4, nullable: true)]
    public ?string $unit_cost_after = null;

    /** The product's total on hand just before the correction moved anything; product-level. */
    #[Column(ColumnType::Int)]
    public int $on_hand_before = 0;

    /** The product's average before, in the store base; null when it had none. Product-level. */
    #[Column(ColumnType::Decimal, precision: 20, scale: 4, nullable: true)]
    public ?string $cost_before = null;

    /** The product's average after, in the store base; null when it had none. Product-level. */
    #[Column(ColumnType::Decimal, precision: 20, scale: 4, nullable: true)]
    public ?string $cost_after = null;

    /**
     * Value in the store base that the stock left could not absorb, recorded instead of forcing it
     * into the average: positive when it already left with shipped units. Zero when absorbed.
     * Product-level: carried on the product's first changed line only, so a sum never counts it twice.
     */
    #[Column(ColumnType::Decimal, precision: 20, scale: 4, default: '0.0000')]
    public string $cost_variance = '0.0000';

    #[Relation(
        RelationType::ManyToOne,
        class: ReceiptCorrection::class,
        foreignKey: 'correction_id',
        onDelete: ForeignKeyAction::Cascade,
    )]
    public ?ReceiptCorrection $correction = null;

    #[Relation(
        RelationType::ManyToOne,
        class: ReceiptLine::class,
        foreignKey: 'receipt_line_id',
        onDelete: ForeignKeyAction::Restrict,
    )]
    public ?ReceiptLine $receiptLine = null;

    #[\Override]
    public function validate(): void
    {
        if ($this->receipt_line_id <= 0 || $this->subject_id <= 0) {
            throw new RecordValidationException(
                'ReceiptCorrectionLine must name a receipt line and its product.',
                ['field' => 'receipt_line_id', 'value' => $this->receipt_line_id],
            );
        }
    }
}
