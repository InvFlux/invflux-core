<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Registry;

use Nandan108\InvFlux\Exceptions\ConfigurationException;

/**
 * Declare one allowed actor type.
 *
 * @api
 */
final class ActorTypeDefinition
{
    /** Build one actor type definition. */
    public function __construct(
        public readonly string $code,
        public readonly string $name,
        public readonly ?string $description = null,
        public readonly bool $active = true,
    ) {
        if ('' === $this->code) {
            throw new ConfigurationException('Actor type code must be a non-empty string.', 'empty_actor_type_code');
        }

        if (strlen($this->code) > 12) {
            throw new ConfigurationException('Actor type code must be at most 12 characters long.', 'actor_type_code_too_long');
        }

        if ('' === $this->name) {
            throw new ConfigurationException('Actor type name must be a non-empty string.', 'empty_actor_type_name');
        }
    }
}
