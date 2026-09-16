<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Exceptions;

/**
 * Thrown when claimIdentifier() finds another active subject already owns the
 * same (system, type, scope, value) under a uniqueActiveValue policy.
 *
 * @api
 */
final class IdentifierAlreadyClaimedException extends InvFluxException
{
    /**
     * @param array<string, mixed> $context
     */
    public function __construct(
        string $message,
        public readonly string $detailCode = 'identifier_already_claimed',
        public readonly array $context = [],
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
