<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Exceptions;

/**
 * Report a quantity that cannot be normalized for persistence.
 *
 * @api
 */
final class InvalidQuantityException extends InvFluxException
{
    /** Build one invalid-quantity exception for the given field. */
    public function __construct(
        public readonly string $field,
        public readonly int | float | null $quantity,
        ?\Throwable $previous = null,
    ) {
        parent::__construct(
            sprintf(
                'Invalid quantity for %s: expected an integer-compatible value, got %s.',
                $field,
                null === $quantity ? 'null' : (string) $quantity,
            ),
            0,
            $previous,
        );
    }
}
