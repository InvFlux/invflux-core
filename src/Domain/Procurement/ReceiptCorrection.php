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
 * A correction to a posted {@see GoodsReceipt}: the receipt was recorded wrong — a quantity, a cost,
 * the good/damaged split, or the whole receipt — and this says so.
 *
 * **A compensating record, never an edit.** The receipt stays exactly as posted; each correction is
 * a record of its own whose {@see ReceiptCorrectionLine}s state, per receipt line, the figures
 * before → after. A receipt line's current figures are its own plus every correction to it, so a
 * second correction starts from where the first one left it.
 *
 * Not a return to the supplier: goods that arrived and go back are their own workflow. A correction
 * says the receipt never matched what arrived.
 *
 * @api
 *
 * @psalm-suppress PossiblyUnusedProperty Properties are hydrated by attrecord from row data.
 */
#[Table(name: 'invflux_receipt_corrections')]
#[LockTier(50)]
final class ReceiptCorrection extends Record
{
    /** The ref type the correction's stock movements carry on the ledger; an integer-keyed referent. */
    public const REF_TYPE = 'receipt_correction';

    #[Column(ColumnType::IntUnsigned, autoIncrement: true)]
    public ?int $id = null;

    #[Column(ColumnType::IntUnsigned)]
    #[Index('idx_receipt')]
    public int $receipt_id = 0;

    #[Column(ColumnType::DateTime, precision: 6)]
    public ?\DateTimeImmutable $corrected_at = null;

    #[Column(ColumnType::IntUnsigned, nullable: true)]
    public ?int $corrected_by = null;

    /** Why, if anyone said: the figures already say what was wrong. */
    #[Column(ColumnType::VarChar, length: 500, nullable: true)]
    public ?string $note = null;

    /**
     * Restrict: a receipt that has been corrected is not deletable without deciding what its
     * corrections then refer to.
     */
    #[Relation(
        RelationType::ManyToOne,
        class: GoodsReceipt::class,
        foreignKey: 'receipt_id',
        onDelete: ForeignKeyAction::Restrict,
    )]
    public ?GoodsReceipt $receipt = null;

    #[\Override]
    public function beforeSave(): void
    {
        if (null === $this->corrected_at) {
            $this->corrected_at = new \DateTimeImmutable();
        }
    }

    #[\Override]
    public function validate(): void
    {
        if ($this->receipt_id <= 0) {
            throw new RecordValidationException(
                'ReceiptCorrection.receipt_id must be a positive integer.',
                ['field' => 'receipt_id', 'value' => $this->receipt_id],
            );
        }
    }
}
