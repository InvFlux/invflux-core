<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Exceptions;

/**
 * Report an unknown or inactive dimension filter.
 *
 * @api
 */
final class InvalidFilterException extends InvFluxException
{
    /** Build one invalid-filter exception for the given dimension. */
    public function __construct(
        public readonly string $dimension,
        ?\Throwable $previous = null,
    ) {
        parent::__construct(
            sprintf('Unknown active dimension "%s".', $dimension),
            0,
            $previous,
        );
    }
}
