<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Contracts\Inventory;

/**
 * How much of each subject's claimed stock no physical unit currently backs.
 *
 * A rising on-hand surface backs what is already claimed before it promises anything new: first the
 * demand of orders that have committed, then the quantity live checkouts are holding, and only the
 * remainder becomes available. Both claims live outside the slot space — in the order domain and in
 * the host's checkout — so the write-in cascade cannot derive them and has to be told.
 *
 * This is the question a write-in asks, answered per subject so one call serves a whole receipt.
 * A subject absent from the result has nothing unbacked, which is the ordinary case.
 *
 * An adapter that tracks no orders binds no implementation, and a write-in then lands entirely on
 * availability — the plain receipt.
 *
 * @api
 */
interface UnbackedClaimReader
{
    /**
     * @param list<int> $subjectIds
     *
     * @return array<int, array{committed: int, reserved: int}> subject id => units still owed to
     *                                                          committed orders, and units held by
     *                                                          live checkouts, that nothing backs.
     *                                                          Both non-negative.
     */
    public function unbackedClaims(array $subjectIds): array;
}
