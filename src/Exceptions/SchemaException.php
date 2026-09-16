<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Exceptions;

/**
 * Report a schema bootstrap or schema-consistency failure.
 *
 * @api
 */
final class SchemaException extends InvFluxException
{
    /**
     * Build one schema exception with a stable detail code and context.
     *
     * @param array<string, mixed> $context
     */
    public function __construct(
        string $message,
        public readonly string $detailCode = 'schema_error',
        public readonly array $context = [],
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
