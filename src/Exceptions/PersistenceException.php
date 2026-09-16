<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Exceptions;

/**
 * Report a storage-layer failure that is not an ordinary business conflict.
 *
 * @api
 *
 * @psalm-suppress PossiblyUnusedProperty
 */
final class PersistenceException extends InvFluxException
{
    /**
     * Build one persistence exception with a stable detail code and context.
     *
     * @param array<string, mixed> $context
     */
    public function __construct(
        string $message,
        public readonly string $detailCode = 'persistence_error',
        public readonly array $context = [],
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
