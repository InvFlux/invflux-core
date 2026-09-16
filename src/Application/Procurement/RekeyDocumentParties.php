<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Application\Procurement;

use Nandan108\Attrecord\RawSql;
use Nandan108\Attrecord\Record;
use Nandan108\Attrecord\WhereClause;
use Nandan108\InvFlux\Domain\Order\Order;
use Nandan108\InvFlux\Domain\Procurement\DocumentParty;
use Nandan108\InvFlux\Domain\Procurement\DocumentPartyDecision;
use Nandan108\InvFlux\Domain\Procurement\DocumentPartyRepository;
use Nandan108\InvFlux\Domain\Procurement\PartyFactNormalizer;
use Nandan108\InvFlux\Domain\Procurement\PurchaseOrder;
use Nandan108\InvFlux\Domain\Procurement\Supplier;
use Nandan108\InvFlux\Domain\Shipment\Shipment;

/**
 * Move every {@see DocumentParty} onto the key its facts imply under the current
 * {@see PartyFactNormalizer}, and repoint everything that referenced the old one.
 *
 * **Why this exists at all.** The party digest *is* the primary key, so the projection the digest
 * compares through is part of the identity function. Change a rule in it and rows that were correct
 * yesterday sit at the wrong key — not wrong in content, but no longer where a fresh intern of the
 * same facts would look. Left alone, the next order restating a known party would mint a *second*
 * row beside it and the dedup the table rests on would quietly stop working. This is the migration
 * that debt is paid with, and it is the reason {@see PartyFactNormalizer::VERSION} exists to make
 * such a change deliberate.
 *
 * **Idempotent and resumable, deliberately in place of a transaction.** A migration that has to be
 * completed in one uninterrupted transaction is a migration that cannot be retried, and this one
 * walks every party a store has ever recorded. So each step is safe to repeat instead: interning is
 * insert-or-ignore by content, a repoint matches only keys that are still old, and a row is deleted
 * only once nothing points at it. Killed halfway, it is finished by running it again.
 *
 * **The ordering invariant is enforced by the database, not by this code.** New rows are written
 * first, then pointers move, then the old rows go — and every FK into this table is `RESTRICT`, so a
 * delete attempted before its pointers moved *fails* rather than orphaning a document. That is worth
 * more than a comment saying the order matters: a future pointer this class does not know about
 * (another table, an add-on's) blocks the delete and is reported, instead of silently losing the row
 * a document depended on.
 *
 * **Run it with no other writer active.** It is a store-wide identity change, not an online
 * operation: a party interned by a concurrent request while this runs lands on the new key and is
 * simply correct, but an order projected against a row this has already moved would take the old
 * key. Nothing here defends against that, and nothing should — the honest fix is exclusivity.
 *
 * @api
 */
final class RekeyDocumentParties
{
    /** Rows loaded per pass. Large enough to amortise the read, small enough to bound memory. */
    private const PAGE = 500;

    /**
     * Old keys named in one repoint statement. Each contributes two placeholders to a CASE and one
     * to the IN, so this stays far below any driver's parameter ceiling.
     */
    private const REPOINT_CHUNK = 200;

    /**
     * Every column in the domain that points at a party.
     *
     * Enumerated rather than discovered because a missed one is not a silent bug here: the row it
     * still references cannot be deleted, and this reports it. The list is the fast path; `RESTRICT`
     * is the correctness guarantee.
     *
     * @var array<class-string<Record>, list<string>>
     */
    private const POINTERS = [
        Order::class    => ['billing_party_id', 'shipping_party_id'],
        // A shipment's `ship_to_party_id` is documented as never rewritten, and repointing it here
        // does not break that: a rekey moves the same facts to a different key, so the address the
        // parcel went to is unchanged and only its name for it moved. Leaving it out would be the
        // actual damage — the shipment would name a key nothing resolves to.
        Shipment::class      => ['ship_to_party_id'],
        PurchaseOrder::class => ['buyer_party_id', 'ship_to_party_id', 'supplier_party_id'],
        Supplier::class      => ['current_party_id'],
        // The decision log is append-only, but a repoint is not an amendment: the row still records
        // the same judgement about the same facts. Omitting it would leave a retirement attached to
        // a key the party no longer has, which reads as "no decision" — a party silently
        // un-retiring itself is exactly what the sidecar exists to prevent.
        DocumentPartyDecision::class => ['party_hash'],
    ];

    public function __construct(
        private readonly DocumentPartyRepository $parties,
        private readonly ReapOrphanedParties $reaper = new ReapOrphanedParties(),
    ) {
    }

    /**
     * @param (\Closure(string): void)|null $progress
     *
     * @return array{parties: int, unchanged: int, rekeyed: int, merged: int, repointed: int, deleted: int, blocked: list<int>}
     */
    public function run(?\Closure $progress = null, bool $dryRun = false): array
    {
        // The whole key list up front, so the walk is not a cursor over a table this same walk is
        // inserting into — which would re-visit the rows it had just written.
        $keys = [];
        foreach (DocumentParty::find() as $party) {
            $keys[] = $party->content_hash;
        }

        $unchanged = 0;
        $rekeyed = 0;
        $merged = 0;
        $repointed = 0;
        $deleted = 0;
        $blocked = [];
        $seenTargets = [];

        foreach (array_chunk($keys, self::PAGE) as $page) {
            /** @var array<int, DocumentParty> $rows */
            $rows = [];
            foreach (DocumentParty::find(WhereClause::whereIn('content_hash', $page)) as $row) {
                $rows[$row->content_hash] = $row;
            }

            // What each row's content says its key should be, computed at attempt 0 — the key the
            // content alone implies, regardless of the slot this row happens to occupy.
            $wanted = [];
            $targets = [];
            foreach ($rows as $oldKey => $row) {
                $restated = DocumentParty::fromFacts($row->facts());
                if ($restated->content_hash === $oldKey) {
                    ++$unchanged;
                    continue;
                }
                $targets[$oldKey] = $restated->content_hash;
                $wanted[$restated->content_hash] ??= $restated;
            }

            if ([] === $targets) {
                $this->report($progress, $unchanged + $rekeyed, \count($keys));
                continue;
            }

            if ($dryRun) {
                foreach ($targets as $target) {
                    isset($seenTargets[$target]) ? ++$merged : $seenTargets[$target] = true;
                    ++$rekeyed;
                }
                $this->report($progress, $unchanged + $rekeyed, \count($keys));
                continue;
            }

            // Reuse the ordinary intern path rather than inserting by hand: it already verifies the
            // row it reads back and steps a genuine collision onto its next attempt. A target
            // momentarily occupied by an *old* row that has not moved yet takes that collision path
            // too — vanishingly rare, and self-correcting, since a later run finds attempt 0 free
            // once that row is gone.
            $interned = $this->parties->internDocumentParties(array_values($wanted));

            $moves = [];
            foreach ($targets as $oldKey => $target) {
                $landed = $interned[$target] ?? null;
                if (null === $landed) {
                    // Nothing to point at: leave the old row and its pointers exactly as they are.
                    $blocked[] = $oldKey;
                    continue;
                }
                $moves[$oldKey] = $landed->content_hash;
                isset($seenTargets[$landed->content_hash]) ? ++$merged : $seenTargets[$landed->content_hash] = true;
                ++$rekeyed;
            }

            $repointed += $this->repoint($moves);
            [$gone, $stuck] = $this->retire(array_keys($moves));
            $deleted += $gone;
            $blocked = [...$blocked, ...$stuck];

            $this->report($progress, $unchanged + $rekeyed, \count($keys));
        }

        return [
            'parties'   => \count($keys),
            'unchanged' => $unchanged,
            'rekeyed'   => $rekeyed,
            'merged'    => $merged,
            'repointed' => $repointed,
            'deleted'   => $deleted,
            'blocked'   => $blocked,
        ];
    }

    /**
     * Point every reference at the new key, one statement per column per chunk.
     *
     * A `CASE` rather than a statement per party: at one update per row this is three round trips
     * per party across the domain, and a store that has been running a while has as many parties as
     * orders. The `WHERE … IN` names only keys that are still old, which is also what makes a second
     * run a no-op instead of a double move.
     *
     * @param array<int, int> $moves old key => new key
     */
    private function repoint(array $moves): int
    {
        if ([] === $moves) {
            return 0;
        }

        $affected = 0;
        foreach (self::POINTERS as $record => $columns) {
            foreach ($columns as $column) {
                foreach (array_chunk($moves, self::REPOINT_CHUNK, true) as $chunk) {
                    $case = 'CASE '.$this->quote($record, $column);
                    $params = [];
                    foreach ($chunk as $oldKey => $newKey) {
                        $case .= ' WHEN ? THEN ?';
                        $params[] = $oldKey;
                        $params[] = $newKey;
                    }
                    // No ELSE: the IN below already restricts the rows, and a bare CASE yields NULL
                    // for an unmatched row — which on a nullable FK would erase a live pointer.
                    $case .= ' ELSE '.$this->quote($record, $column).' END';

                    $affected += $record::updateWhere(
                        [$column => new RawSql($case, $params)],
                        WhereClause::whereIn($column, array_keys($chunk)),
                    );
                }
            }
        }

        return $affected;
    }

    /**
     * Delete the rows this pass superseded, and name the ones that refuse to go.
     *
     * The removal belongs to {@see ReapOrphanedParties}; the *policy* differs and stays here. That
     * class spares anything interned in the last minute, because a party with no pointer yet may be
     * one a concurrent request is about to reference. A migration must move every row including that
     * one, so it calls the unguarded form deliberately — safe only because this runs with no other
     * writer active, the condition the class docblock states.
     *
     * **A survivor is a finding, not a failure.** Every pointer this class knows about has just been
     * moved, so a row that is still referenced is held by a reference nobody wrote down — another
     * table, an add-on's foreign key. Naming it costs one query, and only when there is something to
     * name; reporting "n blocked" without saying which would leave the next reader nothing to chase.
     *
     * @param list<int> $oldKeys
     *
     * @return array{0: int, 1: list<int>} deleted count, and the keys still referenced
     */
    private function retire(array $oldKeys): array
    {
        $deleted = $this->reaper->deleteIfUnreferenced($oldKeys);
        if ($deleted === \count($oldKeys)) {
            return [$deleted, []];
        }

        $held = [];
        foreach (DocumentParty::find(WhereClause::whereIn('content_hash', $oldKeys)) as $survivor) {
            $held[] = $survivor->content_hash;
        }

        return [$deleted, $held];
    }

    /**
     * @param class-string<Record> $record
     */
    private function quote(string $record, string $column): string
    {
        return $record::connection()->dialect->quoteIdentifier($column);
    }

    /** @param (\Closure(string): void)|null $progress */
    private function report(?\Closure $progress, int $done, int $total): void
    {
        if (null !== $progress) {
            $progress(\sprintf('  %s / %s parties…', number_format($done), number_format($total)));
        }
    }
}
