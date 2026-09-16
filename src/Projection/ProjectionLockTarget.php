<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Projection;

/**
 * Describe one logical projection resource that should be locked.
 *
 * @api
 */
final class ProjectionLockTarget
{
    /** Build one logical projection lock target. */
    public function __construct(
        public readonly string $resourceType,
        public readonly string $resourceKey,
    ) {
    }
}
