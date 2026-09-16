<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Domain\Procurement;

use Nandan108\Attrecord\AppendOnly;
use Nandan108\Attrecord\Attribute\Column;
use Nandan108\Attrecord\Attribute\Index;
use Nandan108\Attrecord\Attribute\LockTier;
use Nandan108\Attrecord\Attribute\Mutable;
use Nandan108\Attrecord\Attribute\Relation;
use Nandan108\Attrecord\Attribute\Table;
use Nandan108\Attrecord\Enum\ColumnType;
use Nandan108\Attrecord\Enum\ForeignKeyAction;
use Nandan108\Attrecord\Enum\RelationType;
use Nandan108\Attrecord\Record;
use Nandan108\InvFlux\Identity\RecordIdentity;

/**
 * An operator's standing judgement about a {@see DocumentParty}: *this contact is no longer valid*,
 * or — later — *it is again*.
 *
 * **A deliberate judgement, never an inference.** A parcel comes back for reasons that have nothing
 * to do with the stated facts being wrong: recipient absent, refused, access restricted, courier
 * error. Nothing here is ever written by a delivery signal.
 *
 * **The subject is the party, not the address.** A party is *this person at this address*, contact
 * block included, so two people in one office building are two parties and retiring one says
 * nothing about the other. Because the key is a content hash, a decision reaches **every** document
 * that stated those facts — every order, every install that ever computed the same key. That reach
 * is the feature. Its corollary belongs in any UI that offers this: correcting a phone typo mints a
 * *new* party, and a retirement on the old one does not follow it, so the offer must read as "this
 * exact stated block", never as "this street address is dead".
 *
 * **Why a sidecar rather than columns on the party.** `#[Mutable]` columns are exactly the columns
 * for which a merge has no canonical answer. A party's content columns merge trivially — the hash
 * coincidence *is* the merge, which is what the table is content-addressed for. A decision is not
 * derived from the content, so coincidence says nothing about it, and one mutable slot has to
 * discard one of two true statements: a merge runs through `insertAll(onConflict: Ignore)` and
 * resolves destination-wins, silently, so which install's judgement survives would depend on the
 * direction the merge was run. Two installs that each retired the same party are two rows here,
 * both true, both kept.
 *
 * The line worth carrying elsewhere: **a decision belongs beside the record; a derived property may
 * live on it.** A bare validity boolean would be a fine `#[Mutable]` column — booleans OR on merge
 * and there is no history to lose. Who decided what, when, and why is a decision.
 *
 * **{@see AppendOnly}, so a reversal is another row.** Retirement gets reversed — *they moved
 * back*, *flagged in error* — and rewriting the first row would destroy the fact that it was ever
 * made. The current standing is the newest row for a hash; the older ones are how it got there.
 *
 * **This is also what pins a retired party against the reaper.** The FK is `RESTRICT` and this
 * table is never deleted from, so a party carrying any decision can no longer be collected as
 * unreferenced. That is intended: the decision would otherwise outlive the row it is about and
 * point at nothing.
 *
 * @api
 *
 * @psalm-suppress PossiblyUnusedProperty Properties are hydrated by attrecord from row data.
 */
#[Table(name: 'invflux_document_party_decisions')]
#[LockTier(39)]
#[Index('idx_party_time', columns: ['party_hash', 'decided_at', 'id'])]
final class DocumentPartyDecision extends Record implements AppendOnly
{
    /**
     * 16-byte binary UUIDv7 — minted on save via RecordIdentity.
     *
     * Not an auto-increment, and that is the same argument the party table makes one level up: two
     * installs merging would each contribute a row 1. A UUIDv7 is unique across installs and still
     * sorts by time, so the union of two decision logs needs no renumbering and stays readable in
     * order.
     */
    #[Column(ColumnType::Binary, length: 16)]
    public ?string $id = null;

    /**
     * The party this is about — {@see DocumentParty::$content_hash}.
     *
     * **`#[Mutable]` on an append-only row, and the warrant is narrow: a rekey renames the party,
     * it does not amend the judgement.** `RekeyDocumentParties` (application layer — named rather
     * than imported, since the domain does not depend on it) moves a party to a new key
     * when its facts collide with a different party's; the facts are unchanged, so this row still
     * asserts what it always did about the same party — only the name for that party moved. Left
     * immutable, a rekey touching a decided party would be refused by the `RESTRICT` below and
     * reported as a blocked row: visible rather than silent, but a stalled migration with no
     * recovery short of hand-written SQL.
     *
     * The cost, stated because the marker cannot distinguish the two: an update could also point a
     * decision at a *different* party, which would be a forgery. Nothing but the rekey writes this
     * column, and nothing else on the row moves at all.
     */
    #[Column(ColumnType::BigInt)]
    #[Mutable]
    public int $party_hash = 0;

    /**
     * `true` retires the party, `false` reinstates it.
     *
     * A boolean rather than a status enum because there are exactly two standings and no path
     * between them that is not one of these rows. What a reader wants — *is it retired now* — is
     * this column on the newest row for the hash.
     */
    #[Column(ColumnType::Bool)]
    public bool $retired = true;

    /**
     * Why, in the operator's words. Optional, because requiring a reason produces "x" rather than
     * the truth, and a decision with no stated reason is still the decision.
     */
    #[Column(ColumnType::VarChar, length: 255, nullable: true)]
    public ?string $reason = null;

    /** invflux_actors.id of whoever decided (soft attribution, no FK — mirrors the event log). */
    #[Column(ColumnType::IntUnsigned, nullable: true)]
    public ?int $actor_id = null;

    /**
     * When the operator decided — distinct from when the row was written, and it is the ordering
     * key. Set explicitly rather than defaulted in the database so a decision imported from another
     * install keeps the moment it was actually made.
     */
    #[Column(ColumnType::DateTime, precision: 6)]
    public ?\DateTimeImmutable $decided_at = null;

    /**
     * `RESTRICT`, so a party under judgement cannot be collected out from under its own decision
     * log. `CASCADE` would be worse than wrong here: it would delete the history *quietly*, at the
     * moment the party stopped being referenced elsewhere, which is exactly when the record of why
     * matters most.
     */
    #[Relation(
        RelationType::ManyToOne,
        class: DocumentParty::class,
        foreignKey: 'party_hash',
        onDelete: ForeignKeyAction::Restrict,
    )]
    public ?DocumentParty $party = null;

    #[\Override]
    public function beforeSave(): void
    {
        if (null === $this->id) {
            $this->id = RecordIdentity::mintId();
        }
        if (null === $this->decided_at) {
            $this->decided_at = new \DateTimeImmutable();
        }
    }
}
