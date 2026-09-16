<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Domain\Procurement;

/**
 * The tax a purchase order states, grouped by rate — net and tax per rate, then the order totals.
 *
 * **Grouped, not blended.** A single "tax: 142.50" line cannot be checked against the supplier's
 * invoice, which is the entire reason a purchase order states tax at all. A mixed-rate basket
 * (reduced-rate goods beside standard-rate ones) is ordinary, so the document has to show each
 * share separately or it is not verifiable.
 *
 * **This is an estimate, and stays one.** Nothing upstream determines tax on a purchase — the
 * supplier's invoice is the authority, and it arrives later. So this is computed for display and
 * never persisted as a snapshot; recording it as fact is how a system ends up with audit data it
 * invented.
 *
 * Amounts are unrounded: the caller formats to whatever precision the document uses, so no rounding
 * policy is baked in here where two renderers would then have to agree on it.
 *
 * @api
 */
final class PurchaseTaxSummary
{
    /**
     * @param list<PurchaseTaxRate> $rates      one entry per distinct rate, ascending
     * @param float|null            $net        sum of the costed lines; null when no line carries a cost
     * @param float|null            $tax        sum of the per-rate tax; null when net is null
     * @param float|null            $gross      net + tax; null when net is null
     * @param bool                  $chargesTax false ⇒ a zero-rated regime, so the rate table is empty by design
     */
    private function __construct(
        public readonly array $rates,
        public readonly ?float $net,
        public readonly ?float $tax,
        public readonly ?float $gross,
        public readonly bool $chargesTax,
    ) {
    }

    /**
     * Group costed line nets by their effective rate.
     *
     * The caller resolves each line's effective rate first (the `line ?? po ?? supplier` cascade) and
     * decides whether the regime charges tax at all — both need records this class deliberately does
     * not see. A zero-rated regime passes `chargesTax: false` and gets a net-only summary rather than
     * a table of 0.00 rows, because "no tax applies" and "tax of zero" read differently on a document.
     *
     * @param list<array{net: float, rate: float}> $lines costed lines only; uncosted ones contribute nothing
     */
    public static function fromLines(array $lines, bool $chargesTax = true): self
    {
        if ([] === $lines) {
            return new self([], null, null, null, $chargesTax);
        }

        $net = 0.0;
        foreach ($lines as $line) {
            $net += $line['net'];
        }

        if (!$chargesTax) {
            return new self([], $net, 0.0, $net, false);
        }

        /** @var array<string, float> $netByRate keyed by the rate's string form so 20.0 and 20 group as one */
        $netByRate = [];
        foreach ($lines as $line) {
            $key = (string) $line['rate'];
            $netByRate[$key] = ($netByRate[$key] ?? 0.0) + $line['net'];
        }
        ksort($netByRate, SORT_NUMERIC);

        $rates = [];
        $tax = 0.0;
        foreach ($netByRate as $rate => $rateNet) {
            $rateTax = $rateNet * ((float) $rate / 100.0);
            $tax += $rateTax;
            $rates[] = new PurchaseTaxRate((float) $rate, $rateNet, $rateTax);
        }

        return new self($rates, $net, $tax, $net + $tax, true);
    }
}
