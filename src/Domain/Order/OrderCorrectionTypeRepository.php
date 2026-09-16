<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Domain\Order;

/**
 * Read access to the seeded {@see OrderCorrectionType} registry.
 *
 * The registry is populated at storage-install time (the canonical seed lives next to
 * the storage layer). Add-on packages may extend with their own codes via the storage
 * implementation's seed entry points; this read interface does not deal with mutation.
 *
 * @api
 */
interface OrderCorrectionTypeRepository
{
    /** Look up one correction type by its code (non-empty), or null if not found. */
    public function findByCode(string $code): ?OrderCorrectionType;

    /**
     * All registered correction types, ordered by code.
     *
     * @return list<OrderCorrectionType>
     */
    public function all(): array;
}
