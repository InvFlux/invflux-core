<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\ReadModels;

use Nandan108\InvFlux\Domain\Subject\SubjectId;

/**
 * Represent one inventory ledger row.
 *
 * @api
 */
final class LedgerRecord
{
    /**
     * Build one ledger read-model row.
     *
     * @param ?array<string, string> $fromDimensions
     * @param ?array<string, string> $toDimensions
     */
    public function __construct(
        /** 16-byte binary UUIDv7. */
        public readonly string $id,
        public readonly SubjectId $subjectId,
        public readonly string $movementTypeOwnerKey,
        public readonly string $movementTypeCode,
        public readonly ?string $fromSlotKey,
        public readonly ?array $fromDimensions,
        public readonly ?string $toSlotKey,
        public readonly ?array $toDimensions,
        public readonly int $quantity,
        public readonly ?int $initialFrom,
        public readonly ?int $initialTo,
        public readonly ?string $referenceType,
        public readonly ?string $referenceId,
        public readonly ?string $actorTypeCode,
        public readonly ?string $actorId,
        public readonly \DateTimeImmutable $recordedAt,
    ) {
    }
}
