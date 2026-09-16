<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Domain\Subject;

/**
 * Input spec for one entry in a bulk resolve-or-register-and-claim operation.
 *
 * Passed to {@see \Nandan108\InvFlux\Contracts\Inventory\SubjectRegistrar::resolveOrCreateManyByIdentifier()}.
 * Per-entry fields cover the create-path needs; the (type, system, scopeActor)
 * tuple is shared across the whole batch and lives on the call site, not here.
 *
 * @api
 */
final class SubjectIdentifierClaim
{
    public function __construct(
        public readonly string $value,
        public readonly SubjectKind $subjectKind = SubjectKind::Unit,
        public readonly ?SubjectId $parentSubjectId = null,
        public readonly bool $isPrimary = false,
    ) {
    }
}
