<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Domain\Procurement;

use Nandan108\Attrecord\Attribute\Column;
use Nandan108\Attrecord\Attribute\Index;
use Nandan108\Attrecord\Attribute\LockTier;
use Nandan108\Attrecord\Attribute\Relation;
use Nandan108\Attrecord\Attribute\Table;
use Nandan108\Attrecord\Attribute\UniqueKey;
use Nandan108\Attrecord\Enum\ColumnType;
use Nandan108\Attrecord\Enum\ForeignKeyAction;
use Nandan108\Attrecord\Enum\RelationType;
use Nandan108\Attrecord\Exception\RecordValidationException;
use Nandan108\Attrecord\Record;
use Nandan108\InvFlux\Domain\Subject\Subject;

/**
 * The commercial relationship between a {@see Supplier} and a {@see Subject}
 * (product/variation) — price, MOQ, case-pack, lead-time, ranking priority.
 *
 * This table carries **no product codes**: the supplier's own SKU and barcodes
 * are `invflux_subject_identifiers` rows scoped via `scope_actor_id` = the
 * supplier's actor (types `supplier_sku` / `supplier_barcode`). Multiple
 * suppliers per product are allowed (uncapped); `priority` ranks them and
 * supersedes any "default supplier" flag.
 *
 * @api
 *
 * @psalm-suppress PossiblyUnusedProperty Properties are hydrated by attrecord from row data.
 */
#[Table(name: 'invflux_supplier_products')]
#[LockTier(31)]
#[UniqueKey('uq_supplier_subject', columns: ['supplier_id', 'subject_id'])]
#[Index('idx_subject', columns: ['subject_id'])]
final class SupplierProduct extends Record
{
    #[Column(ColumnType::IntUnsigned, autoIncrement: true)]
    public ?int $id = null;

    #[Column(ColumnType::IntUnsigned)]
    public int $supplier_id = 0;

    #[Column(ColumnType::IntUnsigned)]
    public int $subject_id = 0;

    /** Purchase price in {@see $currency} (defaults to the supplier's currency). */
    #[Column(ColumnType::Decimal, precision: 10, scale: 4, nullable: true)]
    public ?string $unit_price = null;

    /** ISO-4217; null falls back to the supplier's default_currency. */
    #[Column(ColumnType::VarChar, length: 3, nullable: true)]
    public ?string $currency = null;

    /** Minimum order quantity. */
    #[Column(ColumnType::IntUnsigned, nullable: true)]
    public ?int $moq = null;

    /** Order multiple (units per case). */
    #[Column(ColumnType::IntUnsigned, nullable: true)]
    public ?int $case_pack = null;

    #[Column(ColumnType::SmallIntUnsigned, nullable: true)]
    public ?int $lead_time_days = null;

    /** Ranking input; lower = preferred. Supersedes a stored "default supplier" flag. */
    #[Column(ColumnType::SmallIntUnsigned, default: 0)]
    public int $priority = 0;

    #[Column(ColumnType::DateTime, precision: 6, nullable: true)]
    public ?\DateTimeImmutable $last_quoted_at = null;

    #[Column(ColumnType::DateTime, precision: 6)]
    public ?\DateTimeImmutable $created_at = null;

    #[Column(ColumnType::DateTime, precision: 6)]
    public ?\DateTimeImmutable $updated_at = null;

    #[Relation(
        RelationType::ManyToOne,
        class: Supplier::class,
        foreignKey: 'supplier_id',
        onDelete: ForeignKeyAction::Cascade,
    )]
    public ?Supplier $supplier = null;

    #[Relation(
        RelationType::ManyToOne,
        class: Subject::class,
        foreignKey: 'subject_id',
        onDelete: ForeignKeyAction::Cascade,
    )]
    public ?Subject $subject = null;

    #[\Override]
    public function beforeSave(): void
    {
        $now = new \DateTimeImmutable();
        $this->updated_at = $now;
        if (null === $this->created_at) {
            $this->created_at = $now;
        }
    }

    #[\Override]
    public function validate(): void
    {
        if ($this->supplier_id <= 0) {
            throw new RecordValidationException(
                'SupplierProduct.supplier_id must be a positive integer.',
                ['field' => 'supplier_id'],
            );
        }
        if ($this->subject_id <= 0) {
            throw new RecordValidationException(
                'SupplierProduct.subject_id must be a positive integer.',
                ['field' => 'subject_id'],
            );
        }
    }
}
