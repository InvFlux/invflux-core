<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Contracts\Inventory;

use Nandan108\InvFlux\Idempotency\IdempotencyKey;
use Nandan108\InvFlux\Idempotency\IdempotentExecution;
use Nandan108\InvFlux\Idempotency\IdempotentOutcome;

/**
 * Execute retry-prone application operations behind persisted idempotency keys.
 *
 * @api
 */
interface IdempotencyStore
{
    /**
     * Execute one operation behind one persisted idempotency key.
     *
     * If a matching terminal outcome was already recorded, the closure is skipped and that
     * stored outcome is replayed. Non-terminal outcomes are returned to the caller but are
     * not recorded, allowing a later retry to execute the closure again.
     *
     * @param \Closure(): IdempotentOutcome $operation
     */
    public function executeIdempotent(IdempotencyKey $key, \Closure $operation): IdempotentExecution;

    /**
     * Read the recorded *terminal* outcome code for a key, or null when no completed
     * claim exists (absent, or still in-progress). A pure read — it never creates a
     * claim — for callers that must branch on whether a prior idempotent operation
     * already ran (e.g. "is this order's reservation still live?").
     */
    public function idempotentOutcome(IdempotencyKey $key): ?string;
}
