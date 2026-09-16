<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Registry;

use Nandan108\InvFlux\Exceptions\ConfigurationException;

/**
 * Declare one allowed surface type — the stable category of an entry point.
 *
 * Surfaces identify *where* an action originated (e.g., `admin_page`, `api`,
 * `cli`, `system`, `webhook`). Individual surfaces (`admin_page:workbench`,
 * `api:invflux_rest`) compose a surface_type + a surface_ref. The type list is
 * seeded; specific surface_refs are registered on first use.
 *
 * @api
 */
final class SurfaceTypeDefinition
{
    public function __construct(
        public readonly string $code,
        public readonly string $name,
        public readonly ?string $description = null,
    ) {
        if ('' === $this->code) {
            throw new ConfigurationException('Surface type code must be a non-empty string.', 'empty_surface_type_code');
        }

        if (strlen($this->code) > 32) {
            throw new ConfigurationException('Surface type code must be at most 32 characters long.', 'surface_type_code_too_long');
        }

        if ('' === $this->name) {
            throw new ConfigurationException('Surface type name must be a non-empty string.', 'empty_surface_type_name');
        }
    }
}
