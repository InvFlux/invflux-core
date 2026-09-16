<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Domain\Costing;

use Nandan108\InvFlux\Domain\Shipment\CostBasis;

/**
 * An immutable unit-cost figure kept together with its valuation basis and currency — the shared shape
 * for every costed value in the system (a subject's cost basis, the at-booking cost on an order line,
 * the at-dispatch COGS on a shipment line).
 *
 * **A cost amount is meaningless without its currency** (standard ERP practice: every monetary value is
 * currency-qualified, so a functional/base-currency change is a controlled conversion, not a silent
 * reinterpretation). This VO enforces the **all-or-nothing invariant**: a snapshot is either fully
 * costed (amount + basis + currency all present) or fully uncosted (all null) — never a bare amount
 * whose currency has to be guessed at the read site.
 *
 * @api
 */
final class CostSnapshot
{
    /**
     * @param ?string $amount   decimal string, denominated in {@see $currency}; null = uncosted
     * @param ?string $currency ISO-4217 currency the amount is in (store-base at the time it was captured)
     */
    public function __construct(
        public readonly ?string $amount,
        public readonly ?CostBasis $basis,
        public readonly ?string $currency,
    ) {
        if (null === $amount) {
            if (null !== $basis || null !== $currency) {
                throw new \InvalidArgumentException('CostSnapshot: an uncosted snapshot must have null basis and currency.');
            }

            return;
        }
        if (null === $basis) {
            throw new \InvalidArgumentException('CostSnapshot: a costed snapshot requires a basis.');
        }
        if (null === $currency || '' === $currency) {
            throw new \InvalidArgumentException('CostSnapshot: a costed snapshot requires a currency.');
        }
    }

    /** A fully-uncosted snapshot — no amount, basis, or currency. */
    public static function uncosted(): self
    {
        return new self(null, null, null);
    }

    /** Whether this carries no cost (a genuinely uncosted line, as distinct from a zero cost). */
    public function isUncosted(): bool
    {
        return null === $this->amount;
    }
}
