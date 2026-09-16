<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Mutation;

use Nandan108\InvFlux\Domain\Subject\SubjectId;
use Nandan108\InvFlux\Exceptions\ConfigurationException;
use Nandan108\SlotFlow\Flow;

/**
 * Describe one storage-backed batch flow execution request.
 *
 * This command describes a higher-level operation that:
 * - selects authoritative inventory from storage
 * - executes a SlotFlow batch movement over that state
 * - persists the resulting deltas through the normal guarded write path
 *
 * @api
 *
 * @psalm-type TSlotFilters = array<non-empty-string, non-empty-string|list<non-empty-string>>
 */
final class StorageBatchFlowRequest
{
    private ?\DateTimeImmutable $resolvedRecordedAt = null;

    /**
     * @param list<SubjectId>|null                                      $subjectIds
     * @param TSlotFilters|null                                         $slotFilters
     * @param array<string, scalar|null>                                $params
     * @param array<string, mixed>                                      $executionContext
     * @param array<int, int>|null                                      $quantitiesBySubjectId SubjectId->id => quantity
     * @param \Closure(SubjectId, list<array<string, mixed>>): int|null $quantityResolver
     * @param non-empty-string|null                                     $layerName             limit to slots of this named layer
     * @param SurfaceReference|null                                     $surface               entry point that submitted the operation
     */
    public function __construct(
        public readonly string $movementTypeOwnerKey,
        public readonly string $movementTypeCode,
        public readonly string | Flow $flow,
        public readonly ?array $subjectIds = null,
        public readonly ?array $slotFilters = null,
        public readonly array $params = [],
        public readonly ?EntityReference $reference = null,
        public readonly ?ActorReference $actor = null,
        public readonly array $executionContext = [],
        public readonly ?\DateTimeImmutable $recordedAt = null,
        public readonly ?array $quantitiesBySubjectId = null,
        public readonly ?\Closure $quantityResolver = null,
        public readonly ?string $layerName = null,
        /**
         * Which entry point submitted this operation — orthogonal to the movement type (what
         * happened), the actor (who initiated it) and the reference (which document caused it).
         * A batch flow could not carry one before, which is why every batch-emitted movement
         * reached the ledger with a null `surface_id` while the single-persist path recorded it.
         */
        public readonly ?SurfaceReference $surface = null,
    ) {
        '' !== trim($this->movementTypeOwnerKey)
            || throw new ConfigurationException('movementTypeOwnerKey must be a non-empty string.', 'empty_movement_type_owner_key');
        '' !== trim($this->movementTypeCode)
            || throw new ConfigurationException('movementTypeCode must be a non-empty string.', 'empty_movement_type_code');
    }

    /** Resolve and memoize the operation timestamp for this request. */
    public function recordedAt(): \DateTimeImmutable
    {
        return $this->resolvedRecordedAt ??= $this->recordedAt ?? new \DateTimeImmutable();
    }
}
