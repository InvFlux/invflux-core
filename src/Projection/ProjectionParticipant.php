<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Projection;

/**
 * Join one trusted projection into the same logical persist operation.
 *
 * @api
 */
interface ProjectionParticipant
{
    /** Return the stable participant key used for ordering and registration. */
    public function key(): string;

    /**
     * Collect the logical resources this participant wants locked.
     *
     * @return list<ProjectionLockTarget>
     */
    public function collectLockTargets(ProjectionContext $context): array;

    /**
     * Acquire any participant-specific locks for the provided targets.
     *
     * @param list<ProjectionLockTarget> $targets
     */
    public function lock(ProjectionContext $context, array $targets): void;

    /** Apply projection writes inside the surrounding persist operation. */
    public function apply(ProjectionContext $context): void;

    /**
     * Run side-effects deferred from {@see apply()} after the persist transaction commits.
     *
     * Use for work that must NOT execute inside the transaction — typically anything that
     * fires hooks/actions, invalidates caches that other observers may immediately re-read,
     * or talks to systems outside the database (queue dispatches, HTTP webhooks, etc.).
     * The matching {@see apply()} should accumulate state on the participant's own
     * properties and reset it at the top of each apply(), so an aborted transaction
     * doesn't leak stale work into a later persist.
     *
     * Called only when the outer transaction committed successfully. If the persist
     * failed or rolled back, this method is not called.
     */
    public function postCommit(): void;
}
