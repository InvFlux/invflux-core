<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Domain\Subject;

/**
 * Declare how one external system uses one identifier type.
 *
 * Storage uses this to enforce active-uniqueness and reuse rules consistently
 * across all identifier lifecycle operations.
 *
 * @api
 */
final class IdentifierAssignmentPolicy
{
    public function __construct(
        /**
         * At most one active (system, type, scope, value) assignment may exist at any time.
         * Required for lifecycle-anchor identifiers — a host's product-record id, say.
         */
        public readonly bool $uniqueActiveValue = false,

        /**
         * When false, an expired value may not be re-claimed for a different subject.
         * Prevents reuse of retired lifecycle-anchor identifiers.
         */
        public readonly bool $reusableAfterExpiry = true,

        /**
         * This identifier is the external system's primary lifecycle anchor.
         * Signals that subject resolution depends on this identifier being present and unambiguous.
         */
        public readonly bool $lifecycleAnchor = false,

        /**
         * Values may be changed over time (e.g. SKU edits).
         * Set false for immutable identifiers like WC post IDs.
         */
        public readonly bool $mutableAlias = true,
    ) {
    }
}
