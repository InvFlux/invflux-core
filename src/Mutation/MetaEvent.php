<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Mutation;

use Nandan108\InvFlux\Exceptions\ConfigurationException;

/**
 * Describe one configuration or schema audit event.
 *
 * @api
 */
final class MetaEvent
{
    private ?\DateTimeImmutable $resolvedRecordedAt = null;

    /**
     * Build one meta event.
     *
     * @param array<string, mixed> $payload
     */
    public function __construct(
        public readonly string $eventType,
        public readonly array $payload = [],
        public readonly ?ActorReference $actor = null,
        public readonly ?EntityReference $reference = null,
        public readonly ?\DateTimeImmutable $recordedAt = null,
    ) {
        if ('' === $this->eventType) {
            throw new ConfigurationException('Meta event type must be a non-empty string.', 'empty_meta_event_type');
        }
    }

    /** Resolve the event timestamp, defaulting to the current time. */
    public function recordedAt(): \DateTimeImmutable
    {
        return $this->resolvedRecordedAt ??= $this->recordedAt ?? new \DateTimeImmutable();
    }
}
