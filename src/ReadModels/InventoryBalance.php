<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\ReadModels;

use Nandan108\InvFlux\Domain\Subject\SubjectId;

/**
 * Represent one current inventory balance row.
 *
 * @api
 */
final class InventoryBalance
{
    /**
     * Build one inventory balance row.
     *
     * @param array<non-empty-string, non-empty-string> $dimensions
     */
    public function __construct(
        public readonly SubjectId $subjectId,
        public readonly int $slotId,
        public readonly string $slotKey,
        public readonly array $dimensions,
        public readonly string $quantity,
    ) {
    }
}
