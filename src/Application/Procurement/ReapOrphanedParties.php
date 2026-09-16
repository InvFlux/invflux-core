<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Application\Procurement;

use Nandan108\Attrecord\WhereClause;
use Nandan108\InvFlux\Domain\Procurement\DocumentParty;

/**
 * Remove {@see DocumentParty} rows nothing points at any more.
 *
 * **Why they arise, and why the count is never the point.** A party is content-addressed, so
 * *editing* one is really minting a second and repointing at it — the row left behind is referenced
 * by nothing unless some document still states those facts. Every path that repoints produces them:
 * a supplier's address saved, a purchase order re-stamped after unlock, an order's address
 * corrected. Deleting one is not lossy, which is the whole reason the record is {@see
 * \Nandan108\Attrecord\Immutable} rather than `AppendOnly`: re-interning the same facts reproduces
 * the *same* key, so an orphan costs a row and never an identity.
 *
 * **Do not key this to a list of mint sites.** That was the original design and the store disproved
 * it inside a day: a handoff measured seven orphans from two known sites, and by the next morning
 * there were twenty-seven from a path neither of us had listed, contributed by a feature another lane
 * had just landed. Mint sites are added by whoever is writing a feature that week; a reaper that has
 * to be told about each one trails the code permanently and fails silently when it falls behind. So
 * {@see sweep()} finds candidates by *asking what is unreferenced*, and {@see forKeys()} exists only
 * as the cheap path for a caller that already holds the outgoing key.
 *
 * **Nothing here decides what is referenced — the catalogue does, and `RESTRICT` is the backstop.**
 * `ReferenceReader` reads the live foreign keys, so a column added by a feature or an add-on is
 * counted without this class knowing it exists. The engine then refuses to delete a held row anyway,
 * which covers the one gap the read cannot: a reference committed between the check and the delete.
 * Two independent guards, and neither is a list anybody maintains.
 *
 * **The removal is one statement.** `Record::deleteUnreferenced()` puts the question inside the
 * delete — a correlated `NOT EXISTS` per referring column, read from the live catalogue — so there is
 * no window between deciding a row is unreferenced and removing it. A two-step check-then-delete is
 * not merely more code: it leaves a gap in which a reference can be committed, and needs `RESTRICT`
 * to cover what the check could not.
 *
 * @api
 */
final class ReapOrphanedParties
{
    /**
     * How recently interned a party may be and still be spared, in seconds.
     *
     * **Not tidiness — the one condition that makes this safe at all.** Interning runs *before* the
     * transaction that writes the pointer, so between the two there is a party nothing references yet
     * and something is about to. `RESTRICT` guards committed references; it knows nothing of one in
     * flight. Sixty seconds is ample against a write path bounded by `innodb_lock_wait_timeout`
     * (fifty here), and it costs only that a just-orphaned row waits a minute for the next pass.
     *
     * Locking instead would be the wrong trade: the row is shared by potentially thousands of
     * documents, so holding it for the duration of every issue serialises unrelated work.
     */
    public const GRACE_SECONDS = 60;

    /** Rows read per page. One catalogue query covers a whole page, so this is the round-trip unit. */
    private const CHUNK = 1000;

    /**
     * Find parties nothing references and remove them.
     *
     * Candidates are taken by age, then narrowed by **asking the database which of them anything
     * points at** — `ReferenceReader::referencedKeys()` reads the live foreign-key catalogue, so a
     * column added by a feature or an add-on that this code has never heard of is included for free.
     * That is the whole design: the alternative, a list of referring columns maintained by hand, is
     * the thing that goes stale the week someone adds a table.
     *
     * Narrowing first also matters for cost. Attempting the delete *as* the test works when most
     * rows are deletable — it is what the re-key migration does — and is pathological here, where
     * almost every candidate is referenced: a thousand round trips and a thousand foreign-key
     * violations in the server log to remove a handful of rows.
     *
     * @param (\Closure(string): void)|null $progress
     *
     * @return array{examined: int, deleted: int, held: int}
     */
    public function sweep(?\Closure $progress = null, ?int $limit = null): array
    {
        $examined = 0;
        $deleted = 0;
        $held = 0;
        $cursor = null;
        $cutoff = $this->cutoff();

        // Paged by ascending key rather than one bounded window. A single `LIMIT n` over an
        // unordered read examines the same n rows on every run, so an orphan sitting past that
        // window is never reached however often this is called — a sweep that reports success and
        // makes no progress. The cursor is what makes a bounded pass still cover the table.
        while (true) {
            $take = self::CHUNK;
            if (null !== $limit) {
                $take = min($take, $limit - $examined);
                if ($take <= 0) {
                    break;
                }
            }

            $where = WhereClause::where('created_at', $cutoff, '<');
            if (null !== $cursor) {
                $where = $where->andWhere(WhereClause::where('content_hash', $cursor, '>'));
            }

            $keys = [];
            foreach (DocumentParty::find($where, [], 'ORDER BY `content_hash` ASC LIMIT '.$take) as $party) {
                $keys[] = $party->content_hash;
            }
            if ([] === $keys) {
                break;
            }

            $cursor = $keys[\count($keys) - 1];
            $examined += \count($keys);

            $removed = $this->deleteIfUnreferenced($keys);
            $deleted += $removed;
            $held += \count($keys) - $removed;

            if (null !== $progress) {
                $progress(\sprintf('  %s examined, %s removed…', number_format($examined), number_format($deleted)));
            }
        }

        return ['examined' => $examined, 'deleted' => $deleted, 'held' => $held];
    }

    /**
     * Reap specific keys a caller has just stopped pointing at — the cheap path.
     *
     * A save that repoints already holds the outgoing key, so this is a single key-set check rather
     * than a walk. It exists *beside* {@see sweep()} and not instead of it: a save site that forgets
     * to call this loses nothing but timeliness, because the sweep finds the row anyway. That is the
     * whole reason the sweep is not keyed to a list of mint sites.
     *
     * Safe with a key that is still referenced, one that never existed, and with `null` entries — a
     * nullable pointer column supplies those for free, and a caller should not have to filter first.
     *
     * @param list<int|null> $keys
     *
     * @return int rows actually removed
     */
    public function forKeys(array $keys): int
    {
        $present = array_values(array_unique(array_filter(
            $keys,
            static fn (?int $k): bool => null !== $k,
        )));
        if ([] === $present) {
            return 0;
        }

        // The grace period applies here too. Holding the key says nothing about who *else* holds it:
        // a concurrent request may have interned this very party a moment ago and be about to write
        // its pointer, and that reference is not committed yet for `RESTRICT` to see.
        $young = [];
        foreach (DocumentParty::find(
            WhereClause::whereIn('content_hash', $present)
                ->andWhere(WhereClause::where('created_at', $this->cutoff(), '>=')),
        ) as $party) {
            $young[$party->content_hash] = true;
        }

        return $this->deleteIfUnreferenced(
            array_values(array_filter($present, static fn (int $k): bool => !isset($young[$k]))),
        );
    }

    /**
     * Remove those of `$keys` that nothing points at, and report how many went.
     *
     * Shared with {@see RekeyDocumentParties}, which needs the same removal without the age
     * predicate — a migration moves every row, including one interned a second ago, and may skip the
     * grace period only because it runs with no other writer active. Keeping the *mechanic* here and
     * the *policy* in the callers is what stops the two drifting into different notions of
     * "unreferenced".
     *
     * Referrers come from the live foreign-key catalogue, so a column added by a feature or an add-on
     * counts without this class knowing it exists — the reason nothing here maintains a list.
     *
     * @param list<int> $keys
     *
     * @return int rows actually removed; fewer than `count($keys)` when some are still referenced
     */
    public function deleteIfUnreferenced(array $keys): int
    {
        return [] === $keys ? 0 : DocumentParty::deleteUnreferenced($keys);
    }

    /** The instant a party must predate to be eligible. */
    private function cutoff(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
            ->modify('-'.self::GRACE_SECONDS.' seconds')
            ->format('Y-m-d H:i:s.u');
    }
}
