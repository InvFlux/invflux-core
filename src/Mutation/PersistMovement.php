<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Mutation;

use Nandan108\InvFlux\Domain\Subject\SubjectId;
use Nandan108\InvFlux\Exceptions\ConfigurationException;
use Nandan108\SlotFlow\MovementResult;

/**
 * Describe one single-subject persistence request.
 *
 * @api
 */
final class PersistMovement
{
    private ?\DateTimeImmutable $resolvedRecordedAt = null;

    /**
     * Build one single-subject persist command.
     *
     * @param array<non-empty-string, QuantityGuard> $guardsBySlotKey
     */
    public function __construct(
        public readonly SubjectId $subjectId,
        public readonly string $movementTypeOwnerKey,
        public readonly string $movementTypeCode,
        public readonly MovementResult $movementResult,
        public readonly ?EntityReference $reference = null,
        public readonly ?ActorReference $actor = null,
        public readonly array $guardsBySlotKey = [],
        public readonly ?\DateTimeImmutable $recordedAt = null,
        public readonly ?SurfaceReference $surface = null,
    ) {
        // SubjectId validates itself in its constructor

        '' !== $this->movementTypeOwnerKey
            || throw new ConfigurationException('movementTypeOwnerKey must be a non-empty string.', 'empty_movement_type_owner_key');

        '' !== $this->movementTypeCode
            || throw new ConfigurationException('movementTypeCode must be a non-empty string.', 'empty_movement_type_code');
    }

    /** Return the guard declared for one slot key, if any. */
    public function guardFor(string $slotKey): ?QuantityGuard
    {
        return $this->guardsBySlotKey[$slotKey] ?? null;
    }

    /** Resolve and memoize the operation timestamp for this command. */
    public function recordedAt(): \DateTimeImmutable
    {
        return $this->resolvedRecordedAt ??= $this->recordedAt ?? new \DateTimeImmutable();
    }
}
