<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Registry;

use Nandan108\InvFlux\Exceptions\ConfigurationException;

/**
 * Declare one allowed movement type owned by a subsystem.
 *
 * @api
 */
final class MovementTypeDefinition
{
    /** Build one movement type definition. */
    public function __construct(
        public readonly string $code,
        public readonly string $name,
        public readonly ?string $description = null,
        public readonly bool $active = true,
    ) {
        if ('' === $this->code) {
            throw new ConfigurationException('Movement type code must be a non-empty string.', 'empty_movement_type_code');
        }

        if (strlen($this->code) > 32) {
            throw new ConfigurationException('Movement type code must be at most 32 characters long.', 'movement_type_code_too_long');
        }

        if ('' === $this->name) {
            throw new ConfigurationException('Movement type name must be a non-empty string.', 'empty_movement_type_name');
        }
    }
}
