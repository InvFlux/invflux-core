<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\License;

/**
 * The lifecycle state carried by a signed license blob.
 *
 * Mirrors the licensing policy's lifecycle states and the runtime access
 * matrix derived from them. The string values are the on-the-wire `state`
 * field of the blob payload.
 *
 * Entitlement rule ({@see self::entitlesManifest()}): every state EXCEPT
 * {@see self::Revoked} grants full membership in the captured feature
 * manifest. The distinction between the granting states is about *update
 * delivery* and *UI noise* (handled by the WP updater and admin notices),
 * not about which features run — operational continuity is the load-bearing
 *  promise.
 *
 * @api
 */
enum LicenseState: string
{
    /** Subscription paid up to date; full entitlement, updates flow. */
    case Active = 'active';

    /** Involuntary payment failure; full entitlement until `grace_until`, updates paused. */
    case Lapsed = 'lapsed';

    /** Merchant cancelled; full entitlement until `paid_through`, then re-issued as Frozen. */
    case Cancelled = 'cancelled';

    /** Cancelled-and-term-ended; features run per captured manifest, no further updates. */
    case Frozen = 'frozen';

    /** For-cause termination; paid features fall to the revoke matrix, Essentials continues. */
    case Revoked = 'revoked';

    /** Time-boxed evaluation; full entitlement until `paid_through`, then revoked-state semantics. */
    case Trial = 'trial';

    /**
     * Whether this state grants full membership in the captured manifest.
     *
     * True for every state except {@see self::Revoked}. A revoked license
     * routes per-feature through the {@see RevokeBehavior} matrix instead.
     */
    public function entitlesManifest(): bool
    {
        return self::Revoked !== $this;
    }
}
