<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Domain\Subject;

use Nandan108\InvFlux\Mutation\ActorReference;

/**
 * Read model for one external identifier assignment row.
 *
 * Returned by resolveIdentifierAssignment() and listSubjectIdentifiers().
 *
 * @api
 */
final class SubjectIdentifierAssignment
{
    public function __construct(
        public readonly SubjectId $subjectId,
        public readonly string $systemSlug,
        public readonly string $typeCode,
        public readonly string $value,
        public readonly ?ActorReference $scopeActor,
        public readonly bool $isPrimary,
        public readonly \DateTimeImmutable $validFrom,
        public readonly \DateTimeImmutable $validTo,
    ) {
    }

    public function isActive(?\DateTimeImmutable $asOf = null): bool
    {
        $asOf ??= new \DateTimeImmutable();

        return $this->validFrom <= $asOf && $asOf < $this->validTo;
    }
}
