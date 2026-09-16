<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Domain\Procurement;

use Nandan108\Attrecord\Attribute\Column;
use Nandan108\Attrecord\Attribute\Table;
use Nandan108\Attrecord\Attribute\UniqueKey;
use Nandan108\Attrecord\Enum\ColumnType;
use Nandan108\Attrecord\Exception\RecordValidationException;
use Nandan108\Attrecord\Record;

/**
 * The work in progress of a reception: what someone has counted so far, and who is counting.
 *
 * It is **not** an unposted {@see GoodsReceipt}. A receipt asserts that stock arrived, is permanent,
 * and is read back as an operational fact; a session says someone is partway through deciding what
 * to write down, and its ordinary endings include being abandoned. The receipt is built *from* a
 * session and the session is then deleted — nothing here survives into it, and the session's id
 * never becomes the receipt's.
 *
 * Keeping the two apart is what stops a half-counted delivery reading as a delivery: every query
 * over receipts asks only what a receipt means, and none of them has to remember to exclude drafts.
 *
 * The three payload columns are separate because two different writers own them. Staged counts and
 * the note come from the operator saving the form; `participants` comes from a presence heartbeat on
 * a timer. Splitting them is what lets a beat refresh presence without reading and rewriting counts
 * it may have just gone stale on.
 *
 * @api
 *
 * @psalm-suppress PossiblyUnusedProperty Properties are hydrated by attrecord from row data.
 */
#[Table(name: 'invflux_receiving_sessions')]
#[UniqueKey('uq_receiving_session_source', columns: ['source_ref_type_id', 'source_id'])]
final class ReceivingSession extends Record
{
    #[Column(ColumnType::IntUnsigned, autoIncrement: true)]
    public ?int $id = null;

    /**
     * Which kind of document is being received against, discriminating {@see $source_id} — the same
     * pair {@see GoodsReceipt} carries, so a session names its target exactly as the receipt it
     * produces will. Both null for an intake answering no document at all.
     */
    #[Column(ColumnType::SmallIntUnsigned, nullable: true)]
    public ?int $source_ref_type_id = null;

    /**
     * The referenced document's id, discriminated by {@see $source_ref_type_id} — no hard foreign
     * key, because the target table varies by kind. NULL exactly when the ref type is.
     *
     * The unique key over the pair is what enforces one open session per document. It admits any
     * number of source-less sessions on purpose: SQL does not collide NULLs in a unique index, and
     * two people taking delivery of two unrelated unsolicited arrivals are not in conflict.
     */
    #[Column(ColumnType::IntUnsigned, nullable: true)]
    public ?int $source_id = null;

    /** Staged counts, `[{"poLineId":N,"received":N,"damaged":N}]`. Draft figures — nothing here has been checked. */
    #[Column(ColumnType::Json, nullable: true)]
    public ?string $lines = null;

    /** Free-text note the operator is composing; carried onto the receipt when one is posted. */
    #[Column(ColumnType::VarChar, length: 500, nullable: true)]
    public ?string $note = null;

    /**
     * Who is in this session and when each was last seen, `{"<userId>":{"name":"…","lastSeen":N}}`.
     *
     * The one part of a session that could never belong to a receipt: it describes an activity in
     * progress, not the arrival. A receipt records `received_by` — one person, the fact of who
     * posted it — which is a different question from who was on the screen.
     */
    #[Column(ColumnType::Json, nullable: true)]
    public ?string $participants = null;

    #[Column(ColumnType::DateTime, precision: 6)]
    public ?\DateTimeImmutable $created_at = null;

    #[Column(ColumnType::DateTime, precision: 6)]
    public ?\DateTimeImmutable $updated_at = null;

    public function hasSource(): bool
    {
        return null !== $this->source_ref_type_id;
    }

    #[\Override]
    public function beforeSave(): void
    {
        $now = new \DateTimeImmutable();
        if (null === $this->created_at) {
            $this->created_at = $now;
        }
        $this->updated_at = $now;
    }

    /**
     * Half a reference is the shape worth refusing loudly, for the reason it is on a receipt: a ref
     * type with no id points at nothing, an id with no ref type points at everything.
     *
     * Unlike a receipt, a source-less session owes no reason. A reason states why stock is here and
     * what that implies about its cost, and nothing has arrived yet — the receipt this session
     * produces is where that has to be answered.
     */
    #[\Override]
    public function validate(): void
    {
        if ((null !== $this->source_ref_type_id) !== (null !== $this->source_id)) {
            throw new RecordValidationException(
                'ReceivingSession source is half-set: source_ref_type_id and source_id must both be present, or both absent.',
                ['field' => 'source_id', 'source_ref_type_id' => $this->source_ref_type_id, 'source_id' => $this->source_id],
            );
        }

        if (null !== $this->source_ref_type_id && ($this->source_ref_type_id <= 0 || (int) $this->source_id <= 0)) {
            throw new RecordValidationException(
                'ReceivingSession.source_ref_type_id and source_id must be positive integers when set.',
                ['field' => 'source_id', 'source_ref_type_id' => $this->source_ref_type_id, 'source_id' => $this->source_id],
            );
        }
    }
}
