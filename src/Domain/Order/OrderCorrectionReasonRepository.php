<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Domain\Order;

/**
 * Read access to the seeded {@see OrderCorrectionReason} registry.
 *
 * Populated at storage-install time from {@see BuiltInCorrectionReasons::all()}.
 * Add-on packages may extend with their own reasons via the forthcoming
 * `OrderCorrectionReasonRegistrar`; this read interface does not deal
 * with mutation.
 *
 * @api
 */
interface OrderCorrectionReasonRepository
{
    /** Look up one reason by its TINYINT id, or null if not found. */
    public function findById(int $id): ?OrderCorrectionReason;

    /** Look up one reason by its stable code, or null if not found. */
    public function findByCode(string $code): ?OrderCorrectionReason;

    /**
     * All registered reasons, ordered by code.
     *
     * @return list<OrderCorrectionReason>
     */
    public function all(): array;
}
