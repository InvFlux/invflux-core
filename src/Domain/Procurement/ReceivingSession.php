<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Domain\Procurement;

use Nandan108\Attrecord\Attribute\Column;
use Nandan108\Attrecord\Attribute\Table;
use Nandan108\Attrecord\Attribute\UniqueKey;
use Nandan108\Attrecord\Caster\EnumCaster;
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
#[UniqueKey('uq_receiving_session_open_key', columns: ['open_key'])]
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

    /**
     * Why stock is arriving, for a session that answers no document — the same value the receipt it
     * produces will carry, and NULL exactly when the session has a source.
     *
     * Chosen when the session is opened and fixed from then on. It decides what the lines owe about
     * cost ({@see ReceiptReason::costSource()}), so a reason changed mid-count would leave staged
     * figures the new reason refuses, or hide ones it no longer asks for.
     */
    #[Column(ColumnType::Enum, nullable: true)]
    #[EnumCaster(ReceiptReason::class)]
    public ?ReceiptReason $reason = null;

    /**
     * Who sold the goods, for stock bought without a purchase order — optional, and carried
     * onto the receipt's own {@see GoodsReceipt::$supplier_id} when it is posted. Naming one lets the
     * count offer that supplier's catalog and default its line costs from the catalog prices.
     * NULL for every other reason, and for a session against a document, which names its supplier
     * itself.
     */
    #[Column(ColumnType::IntUnsigned, nullable: true)]
    public ?int $supplier_id = null;

    /**
     * The rate the count converts the supplier's catalog currency at — units of base per one
     * supplier-currency unit, the same direction as {@see GoodsReceipt::$fx_rate}, which it becomes
     * when the session is posted. Set only while the supplier prices in a currency other than the
     * store base; NULL otherwise.
     */
    #[Column(ColumnType::Decimal, precision: 18, scale: 8, nullable: true)]
    public ?string $cost_fx_rate = null;

    /**
     * The client's token for the request that opened this session, when it sent one.
     *
     * Opening a count is not naturally idempotent — two source-less sessions never collide — so a
     * create whose response was lost, retried, would otherwise open a second count over the same
     * goods, and posting both books them twice. The unique key makes the retry find the first.
     * NULL for a session opened without a token, and for every session against a document, which
     * the source pair already keeps to one.
     */
    #[Column(ColumnType::VarChar, length: 64, nullable: true)]
    public ?string $open_key = null;

    /**
     * Staged counts, `[{"subjectId":N,"received":N,"damaged":N,"unitCost":"9.5000"|null,
     * "supplierPrice":"9.5000"|null}]` — a line named by its product, the order being the session's
     * own. The two prices are *overrides*, null where the line inherits: `unitCost` in the store base,
     * `supplierPrice` in the supplier's currency when that differs. Both appear only on a session with
     * a reason. Draft figures: nothing here has been checked.
     */
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
     * A session is well-formed in the same two ways its receipt will be: it **answers a document** —
     * both halves of the ref, no reason — or it is a **source-less intake**, with neither half and a
     * reason.
     *
     * The reason is required up front rather than at posting because it shapes the count itself:
     * whether a cost is asked for on each line, and whether it must be given.
     *
     * Half a reference is the shape worth refusing loudly, for the reason it is on a receipt: a ref
     * type with no id points at nothing, an id with no ref type points at everything.
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

        if (null !== $this->source_ref_type_id && null !== $this->reason) {
            throw new RecordValidationException(
                'ReceivingSession.reason belongs to a source-less intake; a session against a document takes its cost from that document.',
                ['field' => 'reason', 'value' => $this->reason->value],
            );
        }

        if (null !== $this->supplier_id && ReceiptReason::PurchaseWithoutOrder !== $this->reason) {
            throw new RecordValidationException(
                'ReceivingSession.supplier_id names who sold stock bought without a purchase order; no other session carries one.',
                ['field' => 'supplier_id', 'value' => $this->supplier_id],
            );
        }

        if (null === $this->source_ref_type_id && null === $this->reason) {
            throw new RecordValidationException(
                'ReceivingSession with no source must carry a reason — it decides what each staged line owes about cost.',
                ['field' => 'reason', 'value' => null],
            );
        }
    }
}
