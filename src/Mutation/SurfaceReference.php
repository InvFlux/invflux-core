<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Mutation;

use Nandan108\InvFlux\Exceptions\ConfigurationException;

/**
 * Identify the surface (entry point) through which an operation entered InvFlux.
 *
 * @api
 */
final class SurfaceReference
{
    public function __construct(
        public readonly string $typeCode,
        public readonly string $ref,
    ) {
        '' !== $this->typeCode
            || throw new ConfigurationException('Surface type code must be a non-empty string.', 'empty_surface_type_code');

        '' !== $this->ref
            || throw new ConfigurationException('Surface ref must be a non-empty string.', 'empty_surface_ref');

        \strlen($this->ref) <= 191
            || throw new ConfigurationException('Surface ref must be at most 191 characters long.', 'surface_ref_too_long');
    }
}
