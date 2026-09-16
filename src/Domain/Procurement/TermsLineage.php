<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Domain\Procurement;

use Nandan108\Attrecord\Attribute\Column;
use Nandan108\Attrecord\Attribute\CreatedAt;
use Nandan108\Attrecord\Attribute\LockTier;
use Nandan108\Attrecord\Attribute\Table;
use Nandan108\Attrecord\Attribute\UniqueKey;
use Nandan108\Attrecord\Enum\ColumnType;
use Nandan108\Attrecord\Record;

/**
 * The **administrative handle** for a set of terms as they evolve — "Standard purchase terms",
 * "Terms for framework suppliers" — under which successive editions are drafted.
 *
 * **This name is internal and never printed.** Orders reference content, not names, so renaming is
 * always safe and needs no lock: no supplier copy changes, and no order's meaning moves. What a
 * supplier sees, where an artefact carries a reference at all, is {@see Terms::$public_ref}, which
 * is a different thing under different rules — unique forever and fixed once assigned.
 *
 * **Once used, the handle is indestructible.** An order stores the interned hash and no version
 * pointer, so this row is the *only* bridge from a hash back to a name. Hard-deleting it makes
 * "which terms went out on PO 4021?" unanswerable except as raw text a year later, which is exactly
 * the question an archive exists to answer. Removal is therefore {@see $removed_at} — the row leaves
 * every picker and stays readable — and the surface presents one button whose label follows usage,
 * so the consequence is visible before the click rather than explained after it.
 *
 * **Removal and retirement are deliberately different states.** Retirement is
 * {@see TermsVersion::$retired_at} and happens on its own when a version is superseded; removal is
 * something a person chose, here. One flag serving both would make every routine "new version" read,
 * in the archive, as though somebody had deleted the old one.
 *
 * **A name belongs to one set**, archived sets included: the name is how a merchant tells two sets
 * apart in every picker, and an archived set still answers "which terms went out on this order?" by
 * it. The use cases refuse a taken name comparing case-insensitively; the unique key is the backstop,
 * and compares as the database collation does, which may also fold accents.
 *
 * @api
 *
 * @psalm-suppress PossiblyUnusedProperty Properties are hydrated by attrecord from row data.
 */
#[Table(name: 'invflux_terms_lineages')]
#[UniqueKey(name: 'uq_terms_lineage_name', columns: ['name'])]
#[LockTier(43)]
final class TermsLineage extends Record
{
    #[Column(ColumnType::BigIntUnsigned, autoIncrement: true)]
    public ?int $id = null;

    /**
     * What the merchant calls this set of terms. Renameable at any time, including after orders have
     * shipped under it — see the class doc for why that is safe rather than merely tolerated.
     */
    #[Column(ColumnType::VarChar, length: 190)]
    public string $name = '';

    /**
     * When a person removed this handle from circulation, or null while it is offered.
     *
     * **There is no separate `active` flag**, on purpose: a boolean beside this timestamp can only
     * ever repeat what the timestamp already says, and the two disagree the first time one is
     * written without the other. "Offered" is `removed_at IS NULL`, asked in one place.
     */
    #[Column(ColumnType::DateTime, precision: 6, nullable: true)]
    public ?\DateTimeImmutable $removed_at = null;

    #[Column(ColumnType::DateTime, precision: 6)]
    #[CreatedAt]
    public ?\DateTimeImmutable $created_at = null;

    public function isRemoved(): bool
    {
        return null !== $this->removed_at;
    }
}
