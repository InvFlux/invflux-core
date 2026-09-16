<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Schema;

/**
 * @api
 */
enum DimensionValueSelectorKind: string
{
    case Root = 'root';
    case Level = 'level';
    case Levels = 'levels';
    case Leaf = 'leaf';
}
