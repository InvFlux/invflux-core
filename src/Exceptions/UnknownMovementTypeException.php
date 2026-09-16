<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Exceptions;

/**
 * Report a movement type that has not been registered.
 *
 * @api
 */
final class UnknownMovementTypeException extends InvFluxException
{
    /** Build one unknown-movement-type exception. */
    public function __construct(
        public readonly string $ownerKey,
        public readonly string $movementCode,
        ?\Throwable $previous = null,
    ) {
        parent::__construct(
            sprintf('Movement type "%s:%s" has not been registered.', $ownerKey, $movementCode),
            0,
            $previous,
        );
    }
}
