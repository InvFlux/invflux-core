<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Exceptions;

/**
 * Thrown by replaceSubjectIdentifier() when the old value has no active
 * assignment for the given subject and scope.
 *
 * @api
 */
final class ActiveIdentifierNotFoundException extends InvFluxException
{
    /**
     * @param array<string, mixed> $context
     */
    public function __construct(
        string $message,
        public readonly string $detailCode = 'active_identifier_not_found',
        public readonly array $context = [],
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
