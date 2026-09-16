<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\License;

/**
 * Access intent for a {@see LicenseGate::allows()} check.
 *
 * Most call sites are at a *mutation* boundary (accepting a Pro action,
 * running a Pro job) and use the default {@see AccessMode::Write}. A
 * call site that only *reads* paid-tier data (rendering a view of past
 * picking runs, listing supplier records) passes {@see AccessMode::Read}
 * so that on a `revoked` / stale-degraded license the data can stay
 * visible while new operations are blocked, per the per-feature read-only
 * matrix.
 *
 * `Write` is the safe default: a bare `allows($key)` at a mutation
 * boundary fails closed under revoke.
 *
 * @api
 */
enum AccessMode
{
    case Read;
    case Write;
}
