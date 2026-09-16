<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Results;

/**
 * Report the outcome of one batch persistence attempt.
 *
 * @api
 */
final class PersistedBatchMovement
{
    /**
     * Build one batch persistence result.
     *
     * @param list<PersistenceConflict> $conflicts
     * @param list<int>                 $skippedUnmanagedSubjectIds Subject IDs that were
     *                                                              omitted from the batch because their
     *                                                              `ivfx_governed` flag is 0 (subject
     *                                                              is not governed by InvFlux). Not an
     *                                                              error condition — callers that care
     *                                                              (reconcilers, diagnostics) can read
     *                                                              this; checkout-style callers ignore it.
     */
    public function __construct(
        public readonly bool $ok,
        public readonly int $affectedSubjects,
        public readonly int $affectedSlots,
        public readonly int $insertedLedgerRows,
        public readonly \DateTimeImmutable $recordedAt,
        public readonly array $conflicts = [],
        public readonly array $skippedUnmanagedSubjectIds = [],
    ) {
    }
}
