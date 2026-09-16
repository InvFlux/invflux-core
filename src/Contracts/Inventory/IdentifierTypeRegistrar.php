<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Contracts\Inventory;

use Nandan108\InvFlux\Domain\Subject\IdentifierAssignmentPolicy;

/**
 * Register identifier type codes and system/type assignment policies.
 *
 * Core seeds only host-neutral types on schema install (sku, gtin, ean13, ean8,
 * upc_a, upc_e, isbn13, isbn10, asin, supplier_sku, supplier_barcode).
 *
 * Host-specific types belong to the platform adapter, which registers its own here —
 * core cannot know a given host's id semantics (whether, say, products and variants
 * share one id sequence or occupy independent, overlapping ones). Adapters and plugins
 * may likewise register additional domain-specific identifiers.
 *
 * @api
 */
interface IdentifierTypeRegistrar
{
    /**
     * Register one identifier type.
     *
     * Idempotent: calling with the same code more than once is safe.
     *
     * @param string      $code     Short unique code, e.g. 'ean13', 'asin'
     * @param string      $name     Human-readable label, e.g. 'EAN-13'
     * @param string|null $category Grouping hint: 'barcode', 'manufacturer', 'marketplace', 'supplier', 'internal'
     */
    public function registerIdentifierType(string $code, string $name, ?string $category = null): void;

    /**
     * Configure how one external system uses one identifier type.
     *
     * Upsert semantics: the same (system, type, scopeActorTypeCode) key with
     * identical policy is a no-op; a different policy updates the existing row.
     *
     * $scopeActorTypeCode scopes the policy to a specific actor type (e.g. 'supplier').
     * Pass null for global/unscoped identifiers such as Woo post IDs.
     */
    public function configureIdentifierAssignment(
        string $systemSlug,
        string $typeCode,
        IdentifierAssignmentPolicy $policy,
        ?string $scopeActorTypeCode = null,
    ): void;
}
