<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Schema;

/**
 * Declares what should happen when a dimension is collapsed away.
 *
 * @api
 */
enum CollapseBehavior: string
{
    case Aggregate = 'aggregate';
    case DropHidden = 'drop_hidden';
    case ForbidIfNonEmpty = 'forbid_if_nonempty';
    case ArchiveThenDrop = 'archive_then_drop';
    case MapToValue = 'map_to_value';
}
