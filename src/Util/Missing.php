<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Util;

/**
 * Sentinel for "no default was supplied" on the {@see Row} readers.
 *
 * `null` cannot carry that meaning, because `null` is itself the most common
 * default a caller wants ("this column is nullable on the wire"). A dedicated
 * enum case keeps the three states — required / default-to-null / default-to-value —
 * distinguishable *and* type-safe, where a magic string or `func_num_args()` would
 * be neither. It is also visible to psalm, which is what lets the readers declare
 * a conditional return type instead of collapsing to a nullable union.
 *
 * @api
 */
enum Missing
{
    case Required;
}
