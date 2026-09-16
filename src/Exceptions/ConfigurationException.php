<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Exceptions;

/**
 * Report invalid configuration or malformed command inputs.
 *
 * @api
 */
final class ConfigurationException extends InvFluxException
{
    /**
     * Build one configuration exception with a stable detail code and context.
     *
     * @param array<string, mixed> $context
     */
    public function __construct(
        string $message,
        public readonly string $detailCode = 'configuration_error',
        public readonly array $context = [],
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
