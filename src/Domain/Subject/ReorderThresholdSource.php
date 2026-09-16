<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Domain\Subject;

/**
 * How a {@see Subject}'s `reorder_threshold` was set: a manual operator entry, or computed by
 * reorder-point (ROP) analysis. Null threshold source ⟹ no threshold set.
 *
 * @api
 */
enum ReorderThresholdSource: string
{
    /** Entered by hand by an operator. */
    case Manual = 'manual';

    /** Computed by ROP / demand analysis. */
    case RopCalculated = 'rop_calculated';
}
