<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Contracts\Inventory;

use Nandan108\InvFlux\Domain\Subject\IdentifierClaimSpec;
use Nandan108\InvFlux\Domain\Subject\SubjectId;
use Nandan108\InvFlux\Domain\Subject\SubjectIdentifierAssignment;
use Nandan108\InvFlux\Domain\Subject\SubjectIdentifierClaim;
use Nandan108\InvFlux\Domain\Subject\SubjectKind;
use Nandan108\InvFlux\Exceptions\ActiveIdentifierNotFoundException;
use Nandan108\InvFlux\Exceptions\AmbiguousIdentifierAssignmentException;
use Nandan108\InvFlux\Exceptions\IdentifierAlreadyClaimedException;
use Nandan108\InvFlux\Exceptions\IdentifierValueRetiredException;
use Nandan108\InvFlux\Mutation\ActorReference;

/**
 * Register subjects and their external identifiers; resolve identifiers to subject IDs.
 *
 * Subjects are the stable identity anchors referenced by the ledger and inventory
 * state. External identifiers (SKU, EAN, ASIN, WooCommerce post ID, …) are
 * managed separately and may change over time.
 *
 * @api
 */
interface SubjectRegistrar
{
    /**
     * Register a new subject and return its stable ID.
     *
     * $kind controls the subject's role in the inventory hierarchy:
     * - Aggregate: grouping node, no direct inventory, children must be Unit or Kit
     * - Unit: commercial inventory unit; may have no parent (simple product)
     *         or an Aggregate parent (variation); may have Batch children
     * - Kit: kit-on-sale parent; holds no slots, components via the BOM table, availability
     *        derived. Same FK shape and parent rules as Unit; never a parent itself.
     * - Batch: physical leaf, parent must be a Unit
     *
     * Allowed parent→child kind pairs:
     * - (none) → Aggregate, Unit or Kit
     * - Aggregate → Unit or Kit
     * - Unit → Batch
     *
     * The implementation automatically computes and persists `product_id` and `variant_id`
     * denormalized FK shortcuts. Callers never supply these fields.
     *
     * | Kind                          | product_id        | variant_id        |
     * |-------------------------------|-------------------|-------------------|
     * | root Unit/Kit (simple)        | self              | self              |
     * | root Aggregate                | self              | null              |
     * | Unit/Kit child of Aggregate   | parent.product_id | self              |
     * | Batch child of Unit           | parent.product_id | parent.variant_id |
     *
     * `$stockManaged` controls whether the subject is InvFlux-governed — whether it
     * participates in inventory movements and read-side stock interception. It defaults
     * to `false` (opt-in): a subject created without an explicit governance decision stays
     * out of InvFlux's hands and behaves as vanilla WooCommerce until deliberately adopted.
     * Pass `true` only when the caller has an explicit decision to govern; governance is
     * otherwise turned on later by an explicit adopt operation in the hosting adapter.
     */
    public function registerSubject(?SubjectId $parentId = null, SubjectKind $kind = SubjectKind::Unit, bool $stockManaged = false): SubjectId;

    /**
     * Claim an external identifier for a subject.
     *
     * Attaches the identifier as the current active owner, enforcing assignment policy:
     * - when uniqueActiveValue is true, throws IdentifierAlreadyClaimedException if
     *   another subject currently holds the same (system, type, scope, value);
     * - when reusableAfterExpiry is false, throws IdentifierValueRetiredException if
     *   any historical row for a different subject exists;
     * - if the same subject already owns the active assignment, returns idempotently.
     *
     * @throws IdentifierAlreadyClaimedException
     * @throws IdentifierValueRetiredException
     */
    public function claimIdentifier(
        SubjectId $subjectId,
        string $typeCode,
        string $systemSlug,
        string $value,
        ?ActorReference $scopeActor = null,
        bool $isPrimary = false,
        ?\DateTimeImmutable $validFrom = null,
    ): void;

    /**
     * Claim many identifiers in one round-trip — the list-shaped twin of {@see claimIdentifier()}.
     *
     * The (type, system, scopeActor) tuple is shared across the batch and passed at call level, as
     * on {@see resolveOrCreateManyByIdentifier()}; only the subject, value and primary flag vary per
     * row. A caller whose scope varies — claiming each supplier's own codes, say — batches per
     * scope, which is a loop over suppliers rather than over rows.
     *
     * **Same semantics as the single-item form, applied to the whole set.** Policy is enforced per
     * value: `uniqueActiveValue` rejects a value another subject actively holds,
     * `reusableAfterExpiry` rejects one historically assigned elsewhere, and a value the same
     * subject already owns is a no-op rather than a duplicate.
     *
     * **All-or-nothing.** One offending row rolls the whole batch back and the caller sees the
     * exception, exactly as for a single claim — there is no skip-the-bad-rows mode here. That
     * policy belongs to the importer, which knows whether a rejected value is a data defect worth
     * stopping for or an expected collision to drop; it can pre-filter with
     * {@see resolveIdentifiers()} or call this per batch and decide in between.
     *
     * Duplicate values *within* one call are a caller error, not something to reconcile silently:
     * two subjects claiming one value cannot both win, and picking for the caller would hide it.
     *
     * @param list<IdentifierClaimSpec> $claims
     *
     * @throws IdentifierAlreadyClaimedException
     * @throws IdentifierValueRetiredException
     */
    public function claimIdentifiers(
        string $typeCode,
        string $systemSlug,
        array $claims,
        ?ActorReference $scopeActor = null,
        ?\DateTimeImmutable $validFrom = null,
    ): void;

    /**
     * Expire the active assignment(s) matching (system, type, scope, value).
     *
     * Returns the number of rows expired. Zero is a valid result when no active
     * assignment matched — the operation is idempotent, not an error.
     */
    public function expireIdentifier(
        string $typeCode,
        string $systemSlug,
        string $value,
        ?ActorReference $scopeActor = null,
        ?\DateTimeImmutable $validTo = null,
    ): int;

    /**
     * Atomically expire the old identifier value and claim the new one.
     *
     * @throws ActiveIdentifierNotFoundException when $oldValue has no active assignment
     *                                           for the given subject and scope
     * @throws IdentifierAlreadyClaimedException when the new value violates uniqueActiveValue
     */
    public function replaceSubjectIdentifier(
        SubjectId $subjectId,
        string $typeCode,
        string $systemSlug,
        string $oldValue,
        string $newValue,
        ?ActorReference $scopeActor = null,
        ?\DateTimeImmutable $changedAt = null,
    ): void;

    /**
     * Re-claim an expired identifier for the same subject, bypassing the
     * reusableAfterExpiry=false guard.
     *
     * uniqueActiveValue is still enforced — cannot re-claim if another subject
     * currently holds the active assignment. Permission-gated at the adapter layer.
     *
     * @throws IdentifierAlreadyClaimedException
     */
    public function reclaimExpiredIdentifier(
        SubjectId $subjectId,
        string $typeCode,
        string $systemSlug,
        string $value,
        ?ActorReference $scopeActor = null,
    ): void;

    /**
     * Change a subject's `kind` in place — the narrow, guarded exception to "kind is written at
     * INSERT and never mutated".
     *
     * Only legal when the subject holds nothing the new kind could misrepresent, which is a much
     * smaller set than it sounds: it must own no slots, have no children, and carry no ledger
     * history. Anything else keeps its history and hands its *identity* to another subject via
     * {@see migrateActiveIdentifiers()} instead — that is still the general mechanism, and this is
     * the case where minting a second subject would only strand the first one's supplier
     * catalogues, purchase-order lines and costs to protect a ledger that does not exist.
     *
     * The self-referential product/variant FKs are re-derived, because their shape follows the
     * kind. Idempotent when the subject is already of $newKind.
     *
     * @throws \Nandan108\InvFlux\Exceptions\ConfigurationException when a guard rejects the retype,
     *                                                              or the new kind is illegal under
     *                                                              the subject's parent
     */
    public function retypeSubject(SubjectId $subjectId, SubjectKind $newKind): void;

    /**
     * Move every *active* identifier assignment from one subject to another, atomically.
     *
     * The whole-identity half of a subject migration: each active row on $from (any type, any
     * system, any scope) is expired and an equivalent open-ended row is created on $to, preserving
     * value, scope and primary flag. Ledger, slots and children are untouched — identity follows
     * the product; history stays behind. An assignment $to already actively holds is left as-is
     * (nothing to move). Bypasses reusableAfterExpiry by design, like
     * {@see reclaimExpiredIdentifier()}; active-uniqueness cannot be violated because every moved
     * value stops being active on $from in the same transaction.
     *
     * @return int the number of assignments moved
     */
    public function migrateActiveIdentifiers(SubjectId $from, SubjectId $to): int;

    /**
     * The subject that most recently *held* an identifier value, among expired assignments only.
     *
     * Supports revival on subject migration: before minting a fresh subject for a post whose kind
     * changed, ask whether a prior subject of the expected kind already carries this post's
     * history. Pass $ofKind to restrict the answer to subjects of that kind; null accepts any.
     * Active assignments are ignored — resolving those is {@see resolveIdentifier()}'s job.
     */
    public function resolveExpiredIdentifierSubject(
        string $typeCode,
        string $systemSlug,
        string $value,
        ?SubjectKind $ofKind = null,
        ?ActorReference $scopeActor = null,
    ): ?SubjectId;

    /**
     * Resolve one external identifier to a subject ID.
     *
     * Returns null when no active matching assignment exists.
     * Throws AmbiguousIdentifierAssignmentException when corrupt data contains
     * multiple active assignments for the same (system, type, scope, value).
     * $asOf defaults to now when omitted.
     *
     * @throws AmbiguousIdentifierAssignmentException
     */
    public function resolveIdentifier(
        string $typeCode,
        string $systemSlug,
        string $value,
        ?ActorReference $scopeActor = null,
        ?\DateTimeImmutable $asOf = null,
    ): ?SubjectId;

    /**
     * Resolve one external identifier to its full assignment record.
     *
     * Returns null when no active matching assignment exists.
     * Throws AmbiguousIdentifierAssignmentException on duplicate active assignments.
     * $asOf defaults to now when omitted.
     *
     * @throws AmbiguousIdentifierAssignmentException
     */
    public function resolveIdentifierAssignment(
        string $typeCode,
        string $systemSlug,
        string $value,
        ?ActorReference $scopeActor = null,
        ?\DateTimeImmutable $asOf = null,
    ): ?SubjectIdentifierAssignment;

    /**
     * List all identifier assignments for a subject.
     *
     * $systemSlug = null returns assignments across all systems.
     * $asOf = null returns currently active assignments; pass a timestamp for historical lookup.
     *
     * @return list<SubjectIdentifierAssignment>
     */
    public function listSubjectIdentifiers(
        SubjectId $subjectId,
        ?string $systemSlug = null,
        ?\DateTimeImmutable $asOf = null,
    ): array;

    /**
     * Bulk twin of {@see listSubjectIdentifiers()}.
     *
     * Lists identifier assignments for many subjects in one round-trip, grouped by subject id.
     * $systemSlug = null spans all systems; pass a slug (e.g. 'woo') to scope. $asOf = null
     * returns currently-active assignments. Subjects with no matching assignment are omitted
     * from the result (not keyed to an empty list); duplicate ids are de-duplicated.
     *
     * @param list<SubjectId> $subjectIds
     *
     * @return array<int, list<SubjectIdentifierAssignment>> keyed by subject id
     */
    public function listSubjectIdentifiersForMany(
        array $subjectIds,
        ?string $systemSlug = null,
        ?\DateTimeImmutable $asOf = null,
    ): array;

    /**
     * Return the kind of a registered subject, or null if the ID does not exist.
     */
    public function resolveSubjectKind(SubjectId $id): ?SubjectKind;

    /**
     * Bulk kind lookup: subject id => {@see SubjectKind}, for every id that resolves to a subject
     * (ids that don't exist are omitted). One query — the set-based form of {@see resolveSubjectKind()}.
     *
     * @param list<int> $ids
     *
     * @return array<int, SubjectKind>
     */
    public function resolveSubjectKinds(array $ids): array;

    /**
     * The `ivfx_governed` bit for many subjects in one round-trip.
     *
     * Returns a map keyed by subject id; unknown ids are omitted (not nulled). This is the read
     * surface for governance *decisions about* subjects (carry a bit across a migration, gate a
     * movement); the write side stays with subject registration and the adapter's governance
     * application flow.
     *
     * @param list<int> $ids
     *
     * @return array<int, bool> keyed by subject id
     */
    public function resolveSubjectGovernance(array $ids): array;

    // -------------------------------------------------------------------------
    // Bulk endpoints
    // -------------------------------------------------------------------------

    /**
     * Bulk twin of {@see resolveIdentifier()}.
     *
     * Resolves many identifier values sharing one (type, system, scopeActor)
     * tuple in a single round-trip. Returns a map keyed by the input value;
     * unmapped values are omitted (not nulled).
     *
     * Use `array_diff_key(array_flip($values), $result)` to compute the
     * missing-set — the most common follow-up question.
     *
     * Throws on duplicate active assignments for any one value (corrupt data
     * — same semantics as the single-item method).
     *
     * @param list<string> $values
     *
     * @return array<int|string, SubjectId> map keyed by input value. PHP normalizes
     *                                      numeric-string keys to int (e.g. '101' → 101),
     *                                      so the key type is int|string in practice;
     *                                      callers should pass through the original
     *                                      input value to look up.
     *
     * @throws AmbiguousIdentifierAssignmentException
     */
    public function resolveIdentifiers(
        string $typeCode,
        string $systemSlug,
        array $values,
        ?ActorReference $scopeActor = null,
        ?\DateTimeImmutable $asOf = null,
    ): array;

    /**
     * Bulk resolve-or-register-and-claim. The **canonical** bulk endpoint of
     * this contract.4.
     *
     * For each {@see SubjectIdentifierClaim} in $claims:
     * - if an active assignment exists for (type, system, scopeActor, value),
     *   the existing subject is reused;
     * - otherwise a new subject is registered with the claim's
     *   subjectKind / parentSubjectId, and the identifier is claimed for it.
     *
     * All work runs in a single transaction. Idempotent by claim value.
     * Constraint violations (e.g. unique-active-value collision with a
     * concurrent writer) roll back the whole batch.
     *
     * The (type, system, scopeActor) tuple is shared across the batch and
     * passed as call-level arguments. The per-claim subjectKind and parent
     * fields differ per row.
     *
     * @param list<SubjectIdentifierClaim> $claims
     *
     * @return array<int|string, SubjectId> input value => synced subject ID; one entry per claim.
     *                                      PHP normalizes numeric-string keys to int — see
     *                                      {@see resolveIdentifiers()} for the same caveat.
     *
     * @throws IdentifierAlreadyClaimedException
     * @throws IdentifierValueRetiredException
     */
    public function resolveOrCreateManyByIdentifier(
        string $typeCode,
        string $systemSlug,
        array $claims,
        ?ActorReference $scopeActor = null,
    ): array;
}
