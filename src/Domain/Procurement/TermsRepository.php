<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Domain\Procurement;

/**
 * Reads and writes for the interned purchase terms.
 *
 * @api
 */
interface TermsRepository
{
    /**
     * Intern exactly what a document states, and return the content hash that names it.
     *
     * Interning is idempotent by construction: the same artefact returns the same hash and mints no
     * second row, so an order can be re-stamped, a request retried, or two orders issued under one
     * text without any of it needing to know what came before.
     *
     * **The caller must not compute the hash, and cannot.** {@see Terms::$content_hash} is a
     * generated column, so the database is the only thing that knows the digest of a given text —
     * which is the point: a value the application could compute is a value it could also state
     * wrongly. Interning therefore writes the text and *reads the hash back*, rather than deriving a
     * key and asserting it the way {@see DocumentPartyRepository} does for parties.
     *
     * **A collision is survived, not refused.** Should different terms already hold the key these
     * would take, the text is stored under its next {@see Terms::$attempt} instead, and the same
     * terms interned again find that row by the same route. A reference already printed on other
     * terms is refused, since that is a reference being reused, not a key colliding.
     *
     * @param string      $body      the terms as normalised Markdown source; normalisation belongs
     *                               to the caller, so identical terms that merely differ in trailing
     *                               whitespace do not intern to two rows
     * @param string|null $publicRef the reference this edition is printed under, where the artefact
     *                               carries one — part of the digest, because it is part of what the
     *                               supplier receives
     *
     * @return int the content hash, as stored
     *
     * @throws \DomainException when `$publicRef` is already printed on different terms
     */
    public function internTerms(string $body, ?string $publicRef = null): int;

    /**
     * The interned terms behind a set of content hashes, keyed by hash.
     *
     * Plural because a hash that is missing is not an error to raise per row: a caller assembling
     * several documents wants the ones that resolve and its own answer for the rest, and asking once
     * is the difference between one query and one per order.
     *
     * @param list<int> $contentHashes content hashes as stored
     *
     * @return array<int, Terms> keyed by content hash; absent for a hash nothing holds
     */
    public function termsByHashes(array $contentHashes): array;

    /**
     * The terms sets, in the order they were created; a removed one only when asked for.
     *
     * @return list<TermsLineage>
     */
    public function termsLineages(bool $includeRemoved = false): array;

    public function findTermsLineage(int $id): ?TermsLineage;

    /**
     * Each set's current edition, keyed by set id — absent for a set that has none.
     *
     * @param list<int> $lineageIds
     *
     * @return array<int, TermsVersion>
     */
    public function currentTermsVersions(array $lineageIds): array;

    /**
     * Every edition of one set, oldest first.
     *
     * @return list<TermsVersion>
     */
    public function termsVersionsOf(int $lineageId): array;

    /**
     * Every edition of each set, oldest first, keyed by set id — absent for a set with none. The plural
     * of {@see termsVersionsOf()}, for a listing that needs every set's editions at once.
     *
     * @param list<int> $lineageIds
     *
     * @return array<int, list<TermsVersion>>
     */
    public function termsVersionsForLineages(array $lineageIds): array;

    /**
     * How many purchase orders were issued under each of these texts, keyed by content hash; a text
     * no order carries is absent.
     *
     * Asked of the text rather than of an edition, because the text is what an order points at: two
     * sets sharing one text share its orders too.
     *
     * @param list<int> $contentHashes
     *
     * @return array<int, int>
     */
    public function orderCountsForTerms(array $contentHashes): array;

    /**
     * Where a set is chosen: the suppliers whose orders carry it, and the draft orders still following
     * it. An order counts only until it is numbered — numbering freezes the text into its own
     * `terms_hash`, after which the set it came from no longer decides anything for it.
     *
     * @return array{suppliers: list<int>, draftOrders: list<int>} ids, ascending
     */
    public function termsLineageSelections(int $lineageId): array;

    /**
     * Create a set together with its first edition, so no set ever exists without one.
     */
    public function createTermsLineage(TermsLineage $lineage, TermsVersion $first): TermsLineage;

    /** Store a change to an existing set's name. */
    public function saveTermsLineage(TermsLineage $lineage): TermsLineage;

    /**
     * Store a set's archival — its `removed_at` — and release the draft orders still following it,
     * in one write, so none is left carrying a set no picker offers any more. A released draft
     * inherits its supplier's set or the store's, as if it had never chosen one.
     *
     * @return list<int> the draft orders released, ascending
     */
    public function archiveTermsLineage(TermsLineage $lineage): array;

    /**
     * Store an edition, retiring the one it supersedes in the same write.
     *
     * Together because the schema allows at most one current edition per set: retiring first and
     * inserting second is the only order that never holds two, and one transaction is what keeps a
     * failure between them from leaving the set with none.
     */
    public function saveTermsVersion(TermsVersion $version, ?TermsVersion $retired = null): TermsVersion;

    /**
     * Delete a set with every edition of it — only ever one no order was issued under — releasing
     * the draft orders still following it in the same write, as {@see archiveTermsLineage()} does.
     * The interned texts stay either way: they belong to no set.
     *
     * @return list<int> the draft orders released, ascending
     */
    public function deleteTermsLineage(TermsLineage $lineage): array;
}
