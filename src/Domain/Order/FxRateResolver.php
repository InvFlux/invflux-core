<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Domain\Order;

/**
 * Resolves an FX context for a monetary {@see OrderEvent} at write time.
 *
 * Every monetary event captures both the source amount/currency (what the merchant
 * or customer sees) AND the install's reporting-currency equivalent + the rate
 * applied. This is captured at *write time* — not derivable post-hoc from a rate
 * table because rates change. The base context is populated unconditionally,
 * including the trivial `base_currency === currency` case, so historical
 * reconstruction can always rely on the persisted VO without inferring intent.
 *
 * Implementations:
 *
 * - {@see IdentityFxRateResolver} — single-rate noop suitable for single-currency
 *   installs (`base_currency = currency`, `fx_rate_used = "1.00000000"`).
 * - Future: a rate-source-backed implementation can plug in here (live rates,
 *   end-of-day fixings, etc.) without changing any emitter call site.
 *
 * @api
 */
interface FxRateResolver
{
    /**
     * Build a fully-populated {@see MonetaryEventPayload} from a source amount +
     * currency, optionally as-of a specific moment.
     *
     * @param string                  $amount   DECIMAL-as-string, e.g. "10.00"
     * @param string                  $currency ISO 4217 alpha-3, e.g. "USD"
     * @param \DateTimeImmutable|null $asOf     when to resolve the rate; null = now
     * @param array<string, mixed>    $extras   event-type-specific keys (passed through verbatim)
     */
    public function resolve(
        string $amount,
        string $currency,
        ?\DateTimeImmutable $asOf = null,
        array $extras = [],
    ): MonetaryEventPayload;
}
