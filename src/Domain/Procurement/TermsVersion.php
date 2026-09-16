<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Domain\Procurement;

use Nandan108\Attrecord\Attribute\Column;
use Nandan108\Attrecord\Attribute\CreatedAt;
use Nandan108\Attrecord\Attribute\ForeignKey;
use Nandan108\Attrecord\Attribute\LockTier;
use Nandan108\Attrecord\Attribute\Table;
use Nandan108\Attrecord\Attribute\UniqueKey;
use Nandan108\Attrecord\Enum\ColumnType;
use Nandan108\Attrecord\Enum\ForeignKeyAction;
use Nandan108\Attrecord\Record;

/**
 * One edition of a lineage's terms: which interned text {@see TermsLineage} pointed at, and when it
 * stopped being the current one.
 *
 * **This is the archive, not the pointer an order follows.** An order stores the interned hash
 * alone — a version id was considered and rejected, because a dispute is about the terms, which are
 * identical under however many names, so a pointer here would record an administrative fact at the
 * cost of a column and a join. Everything about provenance still survives, because the printed
 * reference is *inside* the digest: the hash identifies exactly what the supplier received.
 *
 * **Usage is a property of the hash, never of this row.** That follows from the order not pointing
 * here, and the rest of the design follows from it. "Referenced" means an order was issued under
 * this text — never that a setting happens to select it, since terms nothing has shipped under stay
 * editable, which is fixing a typo before it goes out rather than tampering with what went.
 *
 * **Two lineages may legitimately point at one interned row**, and the archive should say so: "this
 * text is known as X, and also as Y" is honest rather than a conflict. It can only happen where
 * nothing distinguishing is printed — an artefact carrying {@see Terms::$public_ref} has that
 * reference inside its digest, so two differently-referenced editions never share a row to begin
 * with.
 *
 * @api
 *
 * @psalm-suppress PossiblyUnusedProperty Properties are hydrated by attrecord from row data.
 */
#[Table(name: 'invflux_terms_versions')]
#[ForeignKey(
    column: 'lineage_id',
    references: TermsLineage::class,
    onDelete: ForeignKeyAction::Cascade,
    onUpdate: ForeignKeyAction::Restrict,
)]
#[ForeignKey(
    column: 'content_hash',
    references: 'invflux_terms',
    referencesColumn: 'content_hash',
    onDelete: ForeignKeyAction::Restrict,
    onUpdate: ForeignKeyAction::Restrict,
)]
#[UniqueKey(name: 'uq_terms_version_current', columns: ['lineage_id', 'is_current'])]
#[UniqueKey(name: 'uq_terms_version_ordinal', columns: ['lineage_id', 'ordinal'])]
#[LockTier(44)]
final class TermsVersion extends Record
{
    #[Column(ColumnType::BigIntUnsigned, autoIncrement: true)]
    public ?int $id = null;

    #[Column(ColumnType::BigIntUnsigned)]
    public int $lineage_id = 0;

    /** Which edition this is within its lineage — the N a merchant sees in "Save version N". */
    #[Column(ColumnType::IntUnsigned)]
    public int $ordinal = 1;

    /**
     * The interned text this edition points at.
     *
     * **`ON UPDATE RESTRICT` is what makes the whole design hold**, and `CASCADE` here would be
     * worse than no protection at all: it would let an edit of a referenced row succeed *and*
     * silently rewrite every pointer to follow it, which is precisely the tamper this table is
     * arranged to refuse.
     */
    #[Column(ColumnType::BigInt)]
    public int $content_hash = 0;

    /**
     * `true` on the lineage's current edition, and **null** on every superseded one — never `false`.
     *
     * That asymmetry is deliberate and is doing real work: the unique key over
     * `(lineage_id, is_current)` then enforces **at most one current version per lineage** in the
     * schema, because SQL's unique indexes ignore nulls. Storing `false` instead would make every
     * retired edition collide with every other one in the same lineage.
     */
    #[Column(ColumnType::Bool, nullable: true)]
    public ?bool $is_current = true;

    /**
     * When this edition was superseded by the next, or null while it is current.
     *
     * Distinct from {@see TermsLineage::$removed_at}: retirement happens by itself when a merchant
     * saves a new version, while removal is a decision someone made about the handle.
     */
    #[Column(ColumnType::DateTime, precision: 6, nullable: true)]
    public ?\DateTimeImmutable $retired_at = null;

    #[Column(ColumnType::DateTime, precision: 6)]
    #[CreatedAt]
    public ?\DateTimeImmutable $created_at = null;

    public function isRetired(): bool
    {
        return null !== $this->retired_at;
    }
}
