<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Idempotency;

/**
 * Report the result of one idempotent execution attempt.
 *
 * @api
 */
final class IdempotentExecution
{
    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        public readonly bool $replayed,
        public readonly bool $terminal,
        public readonly string $outcomeCode,
        public readonly array $payload = [],
        public readonly ?\DateTimeImmutable $completedAt = null,
    ) {
    }
}
