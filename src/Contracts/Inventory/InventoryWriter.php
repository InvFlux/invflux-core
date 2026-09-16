<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Contracts\Inventory;

use Nandan108\InvFlux\Mutation\PersistBatchMovement;
use Nandan108\InvFlux\Mutation\PersistMovement;
use Nandan108\InvFlux\Mutation\StorageBatchFlowRequest;
use Nandan108\InvFlux\Results\PersistedBatchMovement;
use Nandan108\InvFlux\Results\PersistedMovement;

/**
 * Persist authoritative inventory movements.
 *
 * @api
 */
interface InventoryWriter
{
    /** Persist one batch movement atomically. */
    public function persistBatch(PersistBatchMovement $movement): PersistedBatchMovement;

    /** Persist one single-subject movement atomically. */
    public function persist(PersistMovement $movement): PersistedMovement;

    /**
     * Execute one storage-backed batch flow: select authoritative inventory for the
     * requested subjects, run the SlotFlow batch movement, and persist the resulting
     * deltas — all through the normal guarded write path, in one statement set.
     *
     * Handles both state-dependent flows (e.g. reservations `atp → res`, which require
     * existing stock) and write-in / create flows (e.g. goods receipt `nil → atp`, whose
     * first movement creates stock from nil). For a write-in flow the requested subjects
     * participate even at zero current stock — a create movement's delta is `+qty`
     * regardless of current state — so first receipts are never silently dropped.
     */
    public function executeBatchFlowFromStorage(StorageBatchFlowRequest $request): PersistedBatchMovement;
}
