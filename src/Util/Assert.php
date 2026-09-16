<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Util;

/**
 * Small assertion helper for adapter internals.
 *
 * @api
 */
final class Assert
{
    /** Assert that the given value is a non-empty string. */
    public static function nonEmptyString(string $value, string $message): string
    {
        ('' !== $value) || throw new \InvalidArgumentException($message);

        return $value;
    }
}
