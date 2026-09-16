<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Mutation;

use Nandan108\InvFlux\Domain\Subject\SubjectId;
use Nandan108\InvFlux\Exceptions\ConfigurationException;
use Nandan108\InvFlux\Layer\BoundaryFlow;
use Nandan108\InvFlux\Schema\DimensionScope;

/**
 * Describe one storage-backed boundary flow execution request.
 *
 * A boundary flow moves stock across two or more inventory layers simultaneously.
 * The storage adapter loads state from all named layers, executes the boundary flow
 * via LayeredMovementEngine, and persists the resulting per-layer deltas in a single
 * transaction.
 *
 * @api
 */
final class StorageBoundaryFlowRequest
{
    private ?\DateTimeImmutable $resolvedRecordedAt = null;

    /**
     * @param list<SubjectId>|null       $subjectIds
     * @param array<string, scalar|null> $params
     * @param array<string, mixed>       $executionContext
     * @param array<int, int>|null       $quantitiesBySubjectId SubjectId->id => quantity
     * @param SurfaceReference|null      $surface               entry point that submitted the operation, recorded on
     *                                                          every ledger row this movement writes. Carried here as
     *                                                          well as on {@see StorageBatchFlowRequest} because
     *                                                          otherwise a caller's provenance would depend on how many
     *                                                          layers the flow happens to span — and a boundary flow is
     *                                                          the *more* auditable operation, not the less
     */
    public function __construct(
        public readonly string $movementTypeOwnerKey,
        public readonly string $movementTypeCode,
        public readonly BoundaryFlow $flow,
        public readonly ?array $subjectIds = null,
        public readonly array $params = [],
        public readonly ?EntityReference $reference = null,
        public readonly ?ActorReference $actor = null,
        public readonly array $executionContext = [],
        public readonly ?\DateTimeImmutable $recordedAt = null,
        public readonly ?array $quantitiesBySubjectId = null,
        public readonly ?DimensionScope $dimensionScope = null,
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
