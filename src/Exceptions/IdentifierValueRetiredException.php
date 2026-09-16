<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Exceptions;

/**
 * Thrown when claimIdentifier() finds a historical row for a different subject
 * under a reusableAfterExpiry=false policy.
 *
 * Use reclaimExpiredIdentifier() to bypass this guard with operator approval.
 *
 * @api
 */
final class IdentifierValueRetiredException extends InvFluxException
{
    /**
     * @param array<string, mixed> $context
     */
    public function __construct(
        string $message,
        public readonly string $detailCode = 'identifier_value_retired',
        public readonly array $context = [],
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
