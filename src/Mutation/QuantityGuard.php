<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Mutation;

use Nandan108\InvFlux\Exceptions\ConfigurationException;

/**
 * Carry one resolved write-time quantity constraint.
 *
 * @api
 */
final class QuantityGuard
{
    /** Build one quantity guard. */
    public function __construct(
        public readonly int $min = 0,
        public readonly ?int $max = null,
    ) {
        if ($this->min < 0) {
            throw new ConfigurationException(
                'Quantity guard `min` must be a non-negative integer.',
                'invalid_quantity_guard_min',
            );
        }

        if (null !== $this->max && $this->max < 0) {
            throw new ConfigurationException(
                'Quantity guard `max` must be a non-negative integer when provided.',
                'invalid_quantity_guard_max',
            );
        }

        if (null !== $this->max && $this->min > $this->max) {
            throw new ConfigurationException(
                'Quantity guard `min` cannot be greater than `max`.',
                'invalid_quantity_guard_bounds',
            );
        }
    }
}
