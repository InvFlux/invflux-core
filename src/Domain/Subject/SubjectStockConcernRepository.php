<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Domain\Subject;

/**
 * Persistence boundary for {@see SubjectStockConcern}.
 *
 * Schema invariant: **no row = no concern**. Implementations enforce
 * this on the write side — `clearFor()` deletes; `upsertBits()`
 * refuses a zero-bits write. The dispatch read path is correct as long
 * as missing rows map to `bits = 0`.
 *
 * @api
 */
interface SubjectStockConcernRepository
{
    /** Return the concern row for a subject, or null when there's no concern. */
    public function findBySubjectId(int $subjectId): ?SubjectStockConcern;

    /**
     * Upsert the non-zero bits for one subject, plus the cached `deficit_qty` magnitude (the
     * unfulfillable quantity behind a `STOCK_DEFICIT` bit; `0` when there's no deficit) and the two
     * operands it was computed from. First write stamps `detected_at`; a write happens whenever
     * `bits` or any of the three quantities changes — a deficit can stay `1` while its operands move
     * from `5 − 4` to `4 − 3`, and a cache that kept the old pair would state an equation that is no
     * longer true.
     *
     * @throws \InvalidArgumentException if `$bits === 0`
     *                                   (use {@see clearFor()} instead)
     */
    public function upsertBits(
        int $subjectId,
        int $bits,
        int $deficitQty = 0,
        int $demandQty = 0,
        int $ctdQty = 0,
    ): void;

    /**
     * Delete the concern row for a subject. Idempotent — no-op when the
     * row doesn't exist.
     */
    public function clearFor(int $subjectId): void;
}
