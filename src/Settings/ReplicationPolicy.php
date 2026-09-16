<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Settings;

/**
 * Per-setting replication policy. Stored on every `invflux_settings` row as
 * the *effective* policy (post merchant-override resolution) so the future
 * federation replication layer reads it directly without per-read derivation.
 *
 * See arch-settings
 * §2 for the resolution rule.
 *
 * @api
 */
enum ReplicationPolicy: string
{
    /** Setting stays on the local install. Federation never crosses the wire. */
    case Local = 'local';

    /** Setting replicates to peer installs via the future federation channel. */
    case Replicated = 'replicated';
}
