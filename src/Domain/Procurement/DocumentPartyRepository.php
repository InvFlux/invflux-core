<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Domain\Procurement;

/**
 * Interning and reading {@see DocumentParty} rows.
 *
 * Split out of {@see PurchaseOrderRepository} once a second kind of document needed it. Parties are
 * not a procurement concern — the same row may be a supplier on a purchase order, a customer on a
 * sales order, and a channel order's ship-to on a third — so a consumer that freezes a party should
 * not have to depend on purchase orders to do it. `PurchaseOrderRepository` extends this, so every
 * existing caller keeps working unchanged.
 *
 * (The namespace still says `Procurement` because {@see DocumentParty} does. Moving both is a rename
 * across every reference and is not worth doing on its own.)
 *
 * @api
 */
interface DocumentPartyRepository
{
    /**
     * Intern parties by content: insert the ones this installation has not seen, and return the row
     * that now holds each set of facts.
     *
     * Returned **keyed by the requested `content_hash`**, while each row carries the *actual* one,
     * so a caller that cares can compare the two:
     *
     *     foreach ($repo->internDocumentParties($incoming) as $asked => $row) {
     *         if ($row->content_hash !== $asked) { // re-point whatever referenced $asked
     *
     * That comparison is what a **merge** between installations runs on: parties reconstructed with
     * {@see DocumentParty::fromFacts()} intern to the local key for their content, and only the rows
     * that actually moved need their referring pointers rewritten — normally none, since identical
     * facts produce identical keys everywhere.
     *
     * Idempotent and safe outside any transaction: rows are append-only, and a retry resolves to the
     * same rows. Interning *outside* one is usually right, because a party is lock tier 27 and a
     * caller holding a lower tier (an order at 20) or about to take a higher one (a supplier at 30)
     * would otherwise have to reason about acquisition order for no gain.
     *
     * @param list<DocumentParty> $parties built via {@see DocumentParty::of()} or
     *                                     {@see DocumentParty::fromFacts()}, so each carries the key its content implies
     *
     * @return array<int, DocumentParty> keyed by the *requested* content_hash
     */
    public function internDocumentParties(array $parties): array;

    /**
     * The party rows a document points at, keyed by id. Missing ids are simply absent — the caller
     * decides what a document with a dangling pointer should state.
     *
     * @param list<int> $ids
     *
     * @return array<int, DocumentParty>
     */
    public function findDocumentParties(array $ids): array;

    /**
     * Append one operator judgement about a party — a retirement, or its reversal.
     *
     * Never an update: {@see DocumentPartyDecision} is append-only, so reinstating is a second row
     * and the first stays readable as the fact that it was once decided otherwise.
     *
     * @throws \Nandan108\InvFlux\Exceptions\PersistenceException if the party does not exist — the
     *                                                            FK is `RESTRICT`, so a decision about nothing is refused rather than stored
     */
    public function recordPartyDecision(DocumentPartyDecision $decision): void;

    /**
     * Where each of these parties currently stands — the **newest** decision per hash.
     *
     * Plural because the caller is always plural: an order states two parties, a screen lists many,
     * and asking per party is how a queue turns into N queries.
     *
     * A hash absent from the result has never been decided about, which is not the same as having
     * been reinstated — `null` means *nobody has said*, a row with `retired = false` means *somebody
     * looked and said it is fine now*. Both read as "not retired"; only one of them is evidence.
     *
     * @param list<int> $partyHashes
     *
     * @return array<int, DocumentPartyDecision> keyed by party hash; absent where never decided
     */
    public function currentPartyDecisions(array $partyHashes): array;
}
