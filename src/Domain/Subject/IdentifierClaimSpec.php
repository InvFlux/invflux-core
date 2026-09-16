<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Domain\Subject;

/**
 * Input spec for one entry in a bulk identifier claim.
 *
 * Passed to {@see \Nandan108\InvFlux\Contracts\Inventory\SubjectRegistrar::claimIdentifiers()}.
 * The (type, system, scopeActor) tuple is shared across the batch and lives on the call site, so
 * only what varies per row is here.
 *
 * Three neighbouring types are easy to confuse, and the distinction is what each one is *for*:
 *
 * - {@see SubjectIdentifierClaim} — input to a bulk **resolve-or-create**, so it carries the fields
 *   the create path needs (kind, parent) and no subject, because the subject may not exist yet.
 * - **This** — input to a bulk **claim against a subject that already exists**, so it carries the
 *   subject and nothing about creation.
 * - {@see SubjectIdentifierAssignment} — the **read model** for an assignment row that exists, with
 *   its validity window. An output, never an input.
 *
 * @api
 */
final class IdentifierClaimSpec
{
    public function __construct(
        public readonly SubjectId $subjectId,
        public readonly string $value,
        public readonly bool $isPrimary = false,
    ) {
    }
}
