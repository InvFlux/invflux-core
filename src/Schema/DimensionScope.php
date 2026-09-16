<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Schema;

/**
 * Scope a boundary flow to a specific value of a shared dimension.
 *
 * The storage adapter translates this per-layer: for layers viewing the dimension
 * at leaf level it matches slots whose ancestor equals the bound value; for layers
 * viewing it at the bound value's depth it matches the slot directly.
 *
 * @api
 */
final class DimensionScope
{
    public function __construct(
        public readonly string $dimension,
        public readonly string $value,
    ) {
    }
}
