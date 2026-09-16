<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Exceptions;

/**
 * Report an actor type that has not been registered.
 *
 * @api
 */
final class UnknownActorTypeException extends InvFluxException
{
    /** Build one unknown-actor-type exception. */
    public function __construct(
        public readonly string $actorTypeCode,
        ?\Throwable $previous = null,
    ) {
        parent::__construct(
            sprintf('Actor type "%s" has not been registered.', $actorTypeCode),
            0,
            $previous,
        );
    }
}
