<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Domain\Procurement;

use Nandan108\Attrecord\Attribute\Column;
use Nandan108\Attrecord\Attribute\Index;
use Nandan108\Attrecord\Attribute\LockTier;
use Nandan108\Attrecord\Attribute\Relation;
use Nandan108\Attrecord\Attribute\Table;
use Nandan108\Attrecord\Caster\EnumCaster;
use Nandan108\Attrecord\Enum\ColumnType;
use Nandan108\Attrecord\Enum\ForeignKeyAction;
use Nandan108\Attrecord\Enum\RelationType;
use Nandan108\Attrecord\Exception\RecordValidationException;
use Nandan108\Attrecord\Record;

/**
 * A named person at a {@see Supplier}. A supplier has many contacts — sales reps,
 * logistics, accounting — and more than one may be a PO-notification recipient
 * (a real-world need: contacts leave and addresses bounce, so a PO can target
 * several people). The send UI lists active contacts, pre-checks the
 * {@see $po_recipient} ones, and remembers the merchant's last pick per supplier.
 *
 * A departed contact is set {@see $status} `inactive` rather than deleted, so the
 * history of past PO sends stays intact while they drop out of the send picker.
 *
 * @api
 *
 * @psalm-suppress PossiblyUnusedProperty Properties are hydrated by attrecord from row data.
 */
#[Table(name: 'invflux_supplier_contacts')]
#[LockTier(38)]
#[Index('idx_supplier', columns: ['supplier_id'])]
final class SupplierContact extends Record
{
    #[Column(ColumnType::IntUnsigned, autoIncrement: true)]
    public ?int $id = null;

    #[Column(ColumnType::IntUnsigned)]
    public int $supplier_id = 0;

    #[Column(ColumnType::VarChar, length: 190)]
    public string $name = '';

    #[Column(ColumnType::VarChar, length: 190, nullable: true)]
    public ?string $email = null;

    #[Column(ColumnType::VarChar, length: 64, nullable: true)]
    public ?string $phone = null;

    /** Free-text role label ("Sales rep", "Logistics", "Accounting"). Not an enum — roles vary too widely. */
    #[Column(ColumnType::VarChar, length: 64, nullable: true)]
    public ?string $role = null;

    #[Column(ColumnType::Text, nullable: true)]
    public ?string $notes = null;

    /** Eligible / default PO-notification recipient — pre-checked in the send picker. */
    #[Column(ColumnType::Bool, default: false)]
    public bool $po_recipient = false;

    /** ENUM value list derived from the caster's enum. */
    #[Column(ColumnType::Enum, default: SupplierStatus::Active)]
    #[EnumCaster(SupplierStatus::class)]
    public SupplierStatus $status = SupplierStatus::Active;

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
                'SupplierContact.supplier_id must be a positive integer.',
                ['field' => 'supplier_id'],
            );
        }
        if ('' === trim($this->name)) {
            throw new RecordValidationException(
                'SupplierContact.name must be a non-empty string.',
                ['field' => 'name'],
            );
        }
        // $status is enum-typed (EnumCaster) — the type system guarantees a valid SupplierStatus.
    }
}
