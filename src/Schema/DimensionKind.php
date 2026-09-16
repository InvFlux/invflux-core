<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Schema;

/**
 * Classify how a dimension changes user-visible inventory semantics.
 *
 * @api
 */
enum DimensionKind: string
{
    /**
     * The dimension only partitions already visible stock.
     */
    case Partition = 'partition';

    /**
     * The dimension reveals hidden semantics that may need explicit collapse handling.
     */
    case Reveal = 'reveal';
}
