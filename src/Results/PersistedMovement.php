<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Results;

/**
 * Report the outcome of one single-subject persistence attempt.
 *
 * @api
 */
final class PersistedMovement
{
    /**
     * Build one single-subject persistence result.
     *
     * @param list<PersistenceConflict> $conflicts
     */
    public function __construct(
        public readonly bool $ok,
        public readonly int $affectedSlots,
        public readonly int $insertedLedgerRows,
        public readonly \DateTimeImmutable $recordedAt,
        public readonly array $conflicts = [],
    ) {
    }
}
