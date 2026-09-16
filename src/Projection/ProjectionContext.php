<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Projection;

use Nandan108\InvFlux\Mutation\SurfaceReference;

/**
 * Provide the staged movement context seen by projection participants.
 *
 * @api
 */
final class ProjectionContext
{
    /**
     * Build one projection context for a single persist operation.
     *
     * @param list<array<string, mixed>> $deltaRows
     * @param list<array<string, mixed>> $lockedInventoryRows
     */
    public function __construct(
        public readonly array $deltaRows,
        public readonly array $lockedInventoryRows,
        public readonly \DateTimeImmutable $recordedAt,
        public readonly string $movementTypeOwnerKey,
        public readonly string $movementTypeCode,
        public readonly ?string $referenceType,
        public readonly ?string $referenceId,
        public readonly ?string $actorTypeCode,
        public readonly ?string $actorId,
        public readonly ?SurfaceReference $surface = null,
        public readonly ?object $runtime = null,
    ) {
    }
}
