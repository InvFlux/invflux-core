<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Idempotency;

use Nandan108\InvFlux\Exceptions\ConfigurationException;

/**
 * Describe the business outcome produced by one idempotent operation attempt.
 *
 * @api
 */
final class IdempotentOutcome
{
    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        public readonly string $outcomeCode,
        public readonly array $payload = [],
        public readonly bool $terminal = true,
    ) {
        if ('' === $this->outcomeCode) {
            throw new ConfigurationException('Idempotent outcome code must be a non-empty string.', 'empty_idempotent_outcome_code');
        }
    }

    /**
     * Build one terminal outcome that should be replayed on later duplicate invocations.
     *
     * @param array<string, mixed> $payload
     */
    public static function terminal(string $outcomeCode, array $payload = []): self
    {
        return new self($outcomeCode, $payload, true);
    }

    /**
     * Build one non-terminal outcome that should remain retryable.
     *
     * @param array<string, mixed> $payload
     */
    public static function retryable(string $outcomeCode, array $payload = []): self
    {
        return new self($outcomeCode, $payload, false);
    }
}
