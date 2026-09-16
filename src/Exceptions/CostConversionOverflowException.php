<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Exceptions;

/**
 * Report a base-currency conversion that cannot be applied because an amount would not fit its column
 * at the given rate.
 *
 * Raised **before** anything is written. The alternative is worse than a refusal: an out-of-range
 * decimal is clamped to the column ceiling rather than rejected, so proceeding would replace one real
 * cost with a fabricated one and leave no trace that it happened.
 *
 * @api
 */
final class CostConversionOverflowException extends InvFluxException
{
    /**
     * @param string         $column  the cost column that would overflow
     * @param string         $amount  the largest stored amount in that column
     * @param numeric-string $rate    the requested rate
     * @param string         $ceiling the largest value the column can hold
     */
    public function __construct(
        public readonly string $column,
        public readonly string $amount,
        public readonly string $rate,
        public readonly string $ceiling,
        ?\Throwable $previous = null,
    ) {
        parent::__construct(
            sprintf(
                'Converting %s at rate %s would take %s past the %s this column can hold.',
                $column,
                $rate,
                $amount,
                $ceiling,
            ),
            0,
            $previous,
        );
    }
}
