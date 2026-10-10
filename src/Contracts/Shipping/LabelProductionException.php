<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Contracts\Shipping;

/**
 * A {@see LabelProvider} could not produce a label.
 *
 * `transient` says whether trying again may succeed (the carrier timed out) or not (the address was
 * refused). The message is shown to the operator, so it should say what to do, in the provider's words.
 *
 * @api
 */
final class LabelProductionException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly bool $transient,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
