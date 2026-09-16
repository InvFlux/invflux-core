<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Contracts\Inventory;

/**
 * Register external systems that contribute identifiers to the subject registry.
 *
 * Each adapter plugin registers its own system slug on activation
 * (e.g. 'woo', 'amazon', 'supplier_acme'). Slugs must be unique store-wide.
 *
 * @api
 */
interface SystemRegistrar
{
    /**
     * Register one external system.
     *
     * Idempotent: calling with the same slug more than once is safe.
     *
     * @param string      $slug   Short unique identifier, e.g. 'woo', 'amazon'
     * @param string      $name   Human-readable label, e.g. 'WooCommerce'
     * @param string|null $plugin Registering plugin slug, for diagnostics
     */
    public function registerSystem(string $slug, string $name, ?string $plugin = null): void;
}
