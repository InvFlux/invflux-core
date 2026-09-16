<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Idempotency;

use Nandan108\InvFlux\Exceptions\ConfigurationException;

/**
 * Identify one logical operation that must not be applied twice.
 *
 * @api
 */
final class IdempotencyKey
{
    public function __construct(
        public readonly string $scope,
        public readonly string $operationKey,
    ) {
        if ('' === $this->scope) {
            throw new ConfigurationException('Idempotency key scope must be a non-empty string.', 'empty_idempotency_scope');
        }

        if ('' === $this->operationKey) {
            throw new ConfigurationException('Idempotency operation key must be a non-empty string.', 'empty_idempotency_operation_key');
        }
    }
}
