<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Domain\Order;

/**
 * Single-currency noop {@see FxRateResolver}: the install's reporting currency is
 * whatever the source currency was, and the rate is always `1.00000000`.
 *
 * Suitable for the common single-currency-merchant case. Multi-currency installs
 * (and any install that wants to settle in a base currency different from the
 * gateway currency) plug in a different implementation via the container without
 * touching any emitter call site.
 *
 * @api
 */
final class IdentityFxRateResolver implements FxRateResolver
{
    public const RATE = '1.00000000';

    #[\Override]
    public function resolve(
        string $amount,
        string $currency,
        ?\DateTimeImmutable $asOf = null,
        array $extras = [],
    ): MonetaryEventPayload {
        return new MonetaryEventPayload(
            amount: $amount,
            currency: $currency,
            base_amount: $amount,
            base_currency: $currency,
            fx_rate_used: self::RATE,
            extras: $extras,
        );
    }
}
