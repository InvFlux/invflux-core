<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Contracts\Inventory;

use Nandan108\InvFlux\Domain\Subject\SubjectId;
use Nandan108\InvFlux\ReadModels\InventoryBalance;
use Nandan108\InvFlux\ReadModels\LedgerRecord;

/**
 * Read authoritative balances and ledger history.
 *
 * @api
 */
interface InventoryReader
{
    /**
     * Return current balances for one subject, optionally filtered by slot dimensions.
     *
     * @param array<non-empty-string, non-empty-string|list<non-empty-string>> $slotFilters
     *
     * @return list<InventoryBalance>
     */
    public function inventoryBalances(SubjectId $subjectId, array $slotFilters = []): array;

    /**
     * Return total on-hand quantity per subject for several subjects in one query —
     * the bulk counterpart of summing {@see inventoryBalances()} per subject, for callers
     * (e.g. weighted-average-cost on goods receipt) that need on-hand for many subjects at
     * once and must not issue one read per subject.
     *
     * The result maps `subject_id => total on-hand`. Subjects with no inventory rows are
     * omitted (the caller treats a missing key as 0).
     *
     * @param list<SubjectId> $subjectIds
     *
     * @return array<int, int> subject_id => summed on-hand quantity
     */
    public function onHandTotalsForSubjects(array $subjectIds): array;

    /**
     * Count the subject's active inventory_state slots whose quantity is non-zero.
     *
     * Spans *all* slot dimensions — on-hand (`oh/*`), transit (`trs/*`),
     * supplier-side (`sup`), and the committed state (`ctd`) — not just
     * on-hand. Used by the deletion guard as the `non_zero_inventory` check:
     * a subject with any non-zero slot still carries stock or a commitment and
     * must not have its platform record deleted. One aggregate query; returns 0
     * when the subject holds no inventory.
     */
    public function countNonZeroSlots(SubjectId $subjectId): int;

    /**
     * Return ledger rows for one subject, optionally filtered by slot dimensions.
     *
     * @param array<non-empty-string, non-empty-string|list<non-empty-string>> $slotFilters
     *
     * @return list<LedgerRecord>
     */
    public function ledger(SubjectId $subjectId, array $slotFilters = [], int $limit = 100, int $offset = 0): array;
}
