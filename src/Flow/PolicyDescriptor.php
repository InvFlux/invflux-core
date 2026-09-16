<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Flow;

/**
 * Adapter for policies that cannot implement SerializablePolicy directly.
 *
 * Wraps any policy object with a caller-supplied definition array. The
 * definition is written to the config ledger snapshot as-is; the caller
 * is responsible for its accuracy and for providing the 'type' key.
 *
 * @api
 */
final class PolicyDescriptor
{
    /**
     * @param object               $policy     the actual policy used at runtime
     * @param array<string, mixed> $definition JSON-serializable description written to the ledger
     * @param string               $type       discriminator key for deserialization
     */
    public function __construct(
        public readonly object $policy,
        public readonly array $definition,
        public readonly string $type,
    ) {
    }
}
