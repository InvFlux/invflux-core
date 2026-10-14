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
 * One part of one line's shortfall, written once by the close-short that wrote it off: how many units,
 * which {@see ShortfallPart}, why ({@see ShortfallCause}) and who answers for it
 * ({@see ShortfallParty}).
 *
 * The rows of one close add up to the `qty_closed_short` it added to each line. A table rather than
 * the event payload, because supplier figures group by cause and party across lines and suppliers, and
 * the payload is display-only; rather than columns on the line, because up to four parts × (qty,
 * cause, party) is a dozen sparse columns. The split between parts is stored here once, as it stood at
 * the close, so no report has to replay the confirmation history.
 *
 * @api
 *
 * @psalm-suppress PossiblyUnusedProperty Properties are hydrated by attrecord from row data.
 */
#[Table(name: 'invflux_po_shortfalls')]
#[LockTier(52)]
final class PoShortfall extends Record
{
    #[Column(ColumnType::IntUnsigned, autoIncrement: true)]
    public ?int $id = null;

    #[Column(ColumnType::IntUnsigned)]
    #[Index('idx_po')]
    public int $po_id = 0;

    #[Column(ColumnType::IntUnsigned)]
    #[Index('idx_subject')]
    public int $subject_id = 0;

    #[Column(ColumnType::Enum)]
    #[EnumCaster(ShortfallPart::class)]
    public ShortfallPart $part = ShortfallPart::Undelivered;

    #[Column(ColumnType::IntUnsigned)]
    public int $qty = 0;

    #[Column(ColumnType::Enum)]
    #[EnumCaster(ShortfallCause::class)]
    public ShortfallCause $cause = ShortfallCause::Unknown;

    #[Column(ColumnType::Enum)]
    #[EnumCaster(ShortfallParty::class)]
    public ShortfallParty $party = ShortfallParty::Unknown;

    /** Whether {@see $party} is the suggestion (from the cause and the Incoterm) rather than the operator's choice. */
    #[Column(ColumnType::Bool, default: true)]
    public bool $party_inferred = true;

    /** The `po.short_closed` event of the close that wrote this. */
    #[Column(ColumnType::IntUnsigned)]
    #[Index('idx_event')]
    public int $event_id = 0;

    /** Free text; required when the cause is unknown. */
    #[Column(ColumnType::VarChar, length: 500, nullable: true)]
    public ?string $note = null;

    #[Column(ColumnType::DateTime, precision: 6, nullable: true)]
    public ?\DateTimeImmutable $created_at = null;

    #[Relation(RelationType::ManyToOne, class: PurchaseOrder::class, foreignKey: 'po_id', onDelete: ForeignKeyAction::Cascade)]
    public ?PurchaseOrder $purchaseOrder = null;

    #[Relation(RelationType::ManyToOne, class: PoEvent::class, foreignKey: 'event_id', onDelete: ForeignKeyAction::Cascade)]
    public ?PoEvent $event = null;

    #[\Override]
    public function beforeSave(): void
    {
        $this->created_at ??= new \DateTimeImmutable();
    }

    #[\Override]
    public function validate(): void
    {
        // `event_id` is the repository's to fill, inside the close's transaction (the event has no id
        // before then); the foreign key is what guarantees it names a real close.
        if ($this->qty <= 0 || $this->po_id <= 0 || $this->subject_id <= 0) {
            throw new RecordValidationException(
                'PoShortfall must name its order and product, and carry a positive quantity.',
                ['field' => 'qty', 'value' => $this->qty],
            );
        }
        if (!$this->part->allows($this->cause)) {
            throw new RecordValidationException(
                sprintf('Cause "%s" does not fit the "%s" part of a shortfall.', $this->cause->value, $this->part->value),
                ['field' => 'cause', 'value' => $this->cause->value],
            );
        }
    }
}
