<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Mutation;

use Nandan108\InvFlux\Exceptions\ConfigurationException;

/**
 * Identify the actor responsible for a movement or meta event.
 *
 * @api
 */
final class ActorReference
{
    /** Build one actor reference. */
    public function __construct(
        public readonly string $typeCode,
        public readonly ?string $actorId = null,
    ) {
        '' !== $this->typeCode
            || throw new ConfigurationException('Actor type code must be a non-empty string.', 'empty_actor_type_code');

        if (null !== $this->actorId) {
            '' !== $this->actorId
                || throw new ConfigurationException('Actor id must be a non-empty string when provided.', 'empty_actor_id');

            \strlen($this->actorId) <= 64
                || throw new ConfigurationException('Actor id must be at most 64 characters long.', 'actor_id_too_long');
        }
    }

    /**
     * Two references identify the same actor when their type code and actor id both match. A null
     * argument never matches, so callers comparing an optional scope can drop their own null guard.
     */
    public function equals(?self $other): bool
    {
        return null !== $other
            && $this->typeCode === $other->typeCode
            && $this->actorId === $other->actorId;
    }
}
