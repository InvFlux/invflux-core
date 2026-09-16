<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Settings;

use Nandan108\InvFlux\Exceptions\InvFluxException;

/**
 * Thrown when a setting value fails its {@see SettingDefinition} validator.
 *
 * Carries the offending key + a human-readable reason so admin surfaces (the
 * Settings UI REST layer) can map a rejected write back to the per-field error.
 * Extends {@see InvFluxException} so it propagates as-is through
 * `MysqlSession::transactional()` (which re-wraps only non-InvFlux throwables) —
 * a validator failure inside a `setBatch()` rolls the batch back and surfaces
 * the original reason, not a generic persistence error.
 *
 * @api
 */
final class InvalidSettingValue extends InvFluxException
{
    public function __construct(
        public readonly string $name,
        public readonly string $reason,
        ?\Throwable $previous = null,
    ) {
        parent::__construct(
            sprintf('Invalid value for setting "%s": %s', $name, $reason),
            0,
            $previous,
        );
    }
}
