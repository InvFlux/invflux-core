<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Domain\Subject;

/**
 * Shared sentinel for open-ended identifier validity windows.
 *
 * Storing a non-null sentinel avoids MySQL NULL uniqueness quirks that would
 * permit multiple "active" assignments for the same (system, type, value) tuple.
 *
 * @api
 */
final class IdentifierValidity
{
    public const OPEN_ENDED = '9999-12-31 23:59:59.999999';

    public static function openEnded(): \DateTimeImmutable
    {
        return new \DateTimeImmutable(self::OPEN_ENDED);
    }
}
