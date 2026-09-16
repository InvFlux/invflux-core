<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Results;

use Nandan108\InvFlux\Domain\Subject\SubjectId;

/**
 * Describe one commit-time business conflict detected by persistence.
 *
 * @api
 */
final class PersistenceConflict
{
    /** Build one persistence conflict record. */
    public function __construct(
        public readonly SubjectId $subjectId,
        public readonly string $slotKey,
        public readonly string $reason,
        public readonly string $currentQuantity,
        public readonly string $delta,
        public readonly string $projectedQuantity,
        public readonly ?string $minQuantity = null,
        public readonly ?string $maxQuantity = null,
    ) {
    }
}
