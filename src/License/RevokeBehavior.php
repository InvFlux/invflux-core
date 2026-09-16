<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\License;

/**
 * How a single paid feature behaves once its license is revoked (or the
 * install has exhausted offline stale-tolerance and degraded).
 *
 * This is a per-feature attribute of the manifest catalogue, captured into
 * each license's snapshot at issuance so revoke behavior grandfathers
 * exactly like membership (,
 * ). The string values are the on-the-wire
 * form in the blob's `feature_revoke_class` map.
 *
 * The default for an unclassified key is {@see self::Blocked} — fail closed.
 *
 * @api
 */
enum RevokeBehavior: string
{
    /** Data stays visible; new operations blocked. `allows(key, Read)` true, `Write` false. */
    case ReadOnly = 'read-only';

    /** Feature disappears; both Read and Write denied. */
    case Blocked = 'blocked';

    /** Both denied AND the UI omits the surface entirely. */
    case Hidden = 'hidden';
}
