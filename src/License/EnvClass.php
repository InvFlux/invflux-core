<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\License;

/**
 * Server-assigned environment classification for an activation seat.
 *
 * The plugin sends raw `env_signals` (WP_DEBUG, WP_ENVIRONMENT_TYPE,
 * hostname patterns); the **license server** applies the permissive
 * OR-detection from and returns the resulting
 * class in the blob. Non-production seats don't count against the strict
 * single-site Pro quota (up to 5 granted automatically).
 *
 * The classification is authoritative on the server side — the plugin
 * never decides its own class.
 *
 * @api
 */
enum EnvClass: string
{
    case Production = 'production';
    case NonProduction = 'non_production';
}
