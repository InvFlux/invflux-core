<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Domain\Subject;

/**
 * The outcome of {@see SubjectDeletionGuard::evaluate()}: whether a subject's
 * platform record may be hard-deleted, and — if not — the list of typed,
 * remediable blockers that must be cleared first.
 *
 * A subject is deletable exactly when the blocker list is empty.
 *
 * @api
 */
final class DeletionVerdict
{
    /**
     * @param list<DeletionBlocker> $blockers
     */
    public function __construct(
        public readonly array $blockers = [],
    ) {
    }

    public function isDeletable(): bool
    {
        return [] === $this->blockers;
    }

    /**
     * @param list<DeletionBlocker> $blockers
     */
    public function withBlockers(array $blockers): self
    {
        return new self($blockers);
    }
}
