<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Schema;

use Nandan108\InvFlux\Exceptions\ConfigurationException;

/**
 * Describe one value inside a slot-space dimension.
 *
 * @api
 */
final class DimensionValueDefinition
{
    /**
     * Build one dimension value definition.
     *
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public readonly string $code,
        public readonly ?string $name = null,
        public readonly string $ownerKey = 'core',
        public readonly bool $active = true,
        public readonly ?string $removalTargetCode = null,
        public readonly array $metadata = [],
        public readonly ?string $parentCode = null,
        public readonly bool $addressable = true,
        public readonly ?string $level = null,
    ) {
        if ('' === $code) {
            throw new ConfigurationException('Dimension value code must be a non-empty string.', 'empty_dimension_value_code');
        }

        if ('' === $ownerKey) {
            throw new ConfigurationException('Dimension value owner key must be a non-empty string.', 'empty_dimension_value_owner_key');
        }
    }
}
