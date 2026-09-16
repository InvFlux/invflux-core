<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Domain\Subject;

use Nandan108\InvFlux\Exceptions\ConfigurationException;

/**
 * Identify one inventory subject by its stable integer primary key.
 *
 * SubjectId is the immutable anchor that ledger and inventory state rows
 * reference. External identifiers (SKU, EAN, ASIN, WooCommerce post ID, …)
 * are stored separately in the subject_identifiers table and may change over
 * time without affecting ledger integrity.
 *
 * @api
 */
final class SubjectId
{
    public function __construct(
        public readonly int $id,
    ) {
        $id > 0 || throw new ConfigurationException(
            'SubjectId must be a positive integer.',
            'invalid_subject_id',
            ['id' => $id],
        );
    }

    public function equals(self $other): bool
    {
        return $this->id === $other->id;
    }
}
