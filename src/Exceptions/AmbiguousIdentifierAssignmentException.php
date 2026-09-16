<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Exceptions;

/**
 * Thrown by resolveIdentifier() when corrupt data contains multiple active
 * assignments for the same (system, type, scope, value).
 *
 * @api
 */
final class AmbiguousIdentifierAssignmentException extends InvFluxException
{
    /**
     * @param array<string, mixed> $context
     */
    public function __construct(
        string $message,
        public readonly string $detailCode = 'ambiguous_identifier_assignment',
        public readonly array $context = [],
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
