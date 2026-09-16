<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Mutation;

use Nandan108\InvFlux\Exceptions\ConfigurationException;

/**
 * Identify one business entity associated with a movement or audit event.
 *
 * @api
 */
final class EntityReference
{
    /** Build one entity reference. */
    public function __construct(
        public readonly string $type,
        public readonly int | string $id,
    ) {
        if ('' === $this->type) {
            throw new ConfigurationException('Reference type must be a non-empty string.', 'empty_reference_type');
        }

        if (is_string($this->id) && '' === $this->id) {
            throw new ConfigurationException('Reference id must be non-empty.', 'empty_reference_id');
        }
    }

    /** Return the reference identifier as a string suitable for persistence. */
    public function idAsString(): string
    {
        return (string) $this->id;
    }
}
