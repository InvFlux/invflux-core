<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Application\Costing;

use Nandan108\Attrecord\RawSql;
use Nandan108\InvFlux\Contracts\Inventory\TransactionalStore;
use Nandan108\InvFlux\Domain\Costing\CostConversionResult;
use Nandan108\InvFlux\Domain\Procurement\SubjectCost;
use Nandan108\InvFlux\Domain\Stock\StockAdjustmentLine;
use Nandan108\InvFlux\Exceptions\CostConversionOverflowException;

/**
 * Re-denominate the **live cost sidecar** into a new operating base currency, at a rate the merchant
 * supplies.
 *
 * A cost amount means nothing without its currency, so changing the store base is a conversion
 * migration rather than a settings toggle: until it runs, a moving average would blend amounts
 * denominated in two bases and quietly corrupt itself. This is the migration.
 *
 * ## What moves, and what deliberately does not
 *
 * Only `SubjectCost` is **live, mutable** cost state — `seed_cost` and `weighted_avg_cost` are read as
 * "the cost *now*", so they must be restated in the base the store now operates in. Everything else
 * holding money is a **frozen historical snapshot** (`OrderLine` / `ShipmentLine` / `StockAdjustmentLine`
 * costs), a **transaction-currency** amount (PO / supplier / receipt), or **revenue** (the host's, per
 * order) — each already grounded by its own currency column, each meaningful only in the currency it
 * was recorded in. Restating them would rewrite history at a rate that was not the rate of the day.
 * Reports crossing the boundary convert at read time, from a rate of their choosing.
 *
 * The one exception is `StockAdjustmentLine` rows written *before* the line was currency-grounded:
 * their amount is real but their currency is implicit. They are stamped with the outgoing base (not
 * converted — they stay history), because after this runs "the base currency" no longer identifies
 * one. Skipping that step is what makes an old adjustment unreadable.
 *
 * ## Correctness properties
 *
 * - **Set-based.** Two `UPDATE`s, whatever the catalogue size — never a per-subject loop.
 * - **Naturally idempotent.** Rows are matched on `cost_currency = $from` and stamped `$to`, so a
 *   re-run of the same conversion matches nothing. A double-submit cannot double-convert.
 * - **Ungrounded rows convert too.** A sidecar row with amounts but no `cost_currency` predates
 *   grounding and is denominated in the outgoing base by definition (that is the assumption the
 *   install-time grounding itself makes), so it converts and gets stamped.
 * - **Overflow is refused, never truncated.** The columns are `DECIMAL(10,4)`; a rate that would push
 *   any amount past that ceiling aborts the whole conversion instead of silently clamping a cost.
 * - **Rounding is 4-dp, once.** `ROUND(amount × rate, 4)` in SQL. Converting back at `1/rate` does not
 *   necessarily restore the original cent — accepted: a WAC is a derived average, not a ledger balance.
 *
 * Platform-agnostic: the caller resolves the currencies (the adapter reads the host's store base and
 * InvFlux's anchor), owns the anchor + audit records, and re-projects any host-side cost mirror.
 *
 * @api
 */
final class ConvertCostBaseCurrency
{
    /** Largest amount `DECIMAL(10,4)` can hold — the conversion refuses rather than clamp to it. */
    public const AMOUNT_CEILING = '999999.9999';

    public function __construct(private readonly TransactionalStore $store)
    {
    }

    /**
     * @param non-empty-string $from ISO-4217 the stored costs are denominated in (InvFlux's anchor)
     * @param non-empty-string $to   ISO-4217 to restate them into (the store's new base)
     * @param numeric-string   $rate units of $to per 1 unit of $from
     *
     * @throws \InvalidArgumentException       on a same-currency, non-positive or malformed request
     * @throws CostConversionOverflowException when an amount would not fit the column at this rate
     */
    public function __invoke(string $from, string $to, string $rate): CostConversionResult
    {
        $from = strtoupper($from);
        $to = strtoupper($to);

        if ($from === $to) {
            throw new \InvalidArgumentException('Base-currency conversion needs two different currencies.');
        }
        if (!is_numeric($rate) || (float) $rate <= 0.0) {
            throw new \InvalidArgumentException('An exchange rate must be a positive number.');
        }

        $this->assertFits($from, $rate);

        return $this->store->transactional(function () use ($from, $to, $rate): CostConversionResult {
            // Ground first, convert second. A pre-grounding adjustment line is denominated in the
            // outgoing base, so it must be stamped while "the base" still unambiguously means $from —
            // after the sidecar moves, that fact is no longer recoverable from anything.
            $grounded = StockAdjustmentLine::updateWhere(
                ['cost_currency' => $from],
                'cost_currency IS NULL AND unit_cost IS NOT NULL',
            );

            // The rate is CAST to DECIMAL, never multiplied in as a bound string. A string parameter
            // makes the engine evaluate the product in floating point, so a cost landing exactly on a
            // rounding boundary can fall the wrong way — 13.5500 × 0.815 is exactly 11.043250 and
            // rounds to 11.0433, but as a float it computes just under and truncates to 11.0432.
            // The audit records an exact rate, so recomputing a stored cost from it has to reproduce
            // that cost; DECIMAL(18,8) is the rate precision used everywhere else.
            $converted = SubjectCost::updateWhere(
                [
                    'seed_cost'         => new RawSql(sprintf('ROUND(%s * CAST(? AS DECIMAL(18,8)), 4)', SubjectCost::stored('seed_cost')), [$rate]),
                    'weighted_avg_cost' => new RawSql(sprintf('ROUND(%s * CAST(? AS DECIMAL(18,8)), 4)', SubjectCost::stored('weighted_avg_cost')), [$rate]),
                    'cost_currency'     => $to,
                ],
                $this->sidecarScope(),
                [$from],
            );

            return new CostConversionResult($from, $to, $rate, $converted, $grounded);
        });
    }

    /**
     * How many sidecar rows this conversion would restate — the preview figure, read with the same
     * predicate the conversion writes with so the count cannot disagree with the outcome.
     *
     * @param non-empty-string $from
     */
    public function countAffected(string $from): int
    {
        return SubjectCost::countWhere($this->sidecarScope(), [strtoupper($from)]);
    }

    /**
     * The largest stored cost denominated in $from, or null when nothing is costed.
     *
     * Doubles as the preview's worked example and as the figure that decides whether a rate fits:
     * showing the merchant what happens to their *largest* cost is what makes an about-to-overflow
     * rate visible before they commit to it, rather than as a refusal afterwards.
     *
     * @param non-empty-string $from
     */
    public function largestAmount(string $from): ?string
    {
        $scope = $this->sidecarScope();
        $from = strtoupper($from);

        $largest = null;
        foreach (['seed_cost', 'weighted_avg_cost'] as $column) {
            /** @psalm-var mixed $max */
            $max = SubjectCost::maxWhere($column, $scope, [$from]);
            if (null !== $max && (null === $largest || (float) $max > (float) $largest)) {
                $largest = (string) $max;
            }
        }

        return $largest;
    }

    /**
     * Costed rows denominated in the outgoing base: explicitly stamped, or ungrounded-but-costed
     * (pre-grounding data, base-denominated by definition). An uncosted row has nothing to convert.
     */
    private function sidecarScope(): string
    {
        return '(seed_cost IS NOT NULL OR weighted_avg_cost IS NOT NULL)'
            .' AND (cost_currency = ? OR cost_currency IS NULL)';
    }

    /**
     * Refuse a rate that would overflow `DECIMAL(10,4)` on any row.
     *
     * Checked up-front against the current maxima rather than discovered mid-`UPDATE`: MySQL clamps an
     * out-of-range decimal to the column ceiling (a warning, not an error, outside strict mode), which
     * would turn one over-large cost into a silently wrong one. Refusing keeps the store on a base it
     * can still describe — the merchant re-runs against a corrected rate, or fixes the outlier cost.
     *
     * @param non-empty-string $from
     * @param numeric-string   $rate
     */
    private function assertFits(string $from, string $rate): void
    {
        $scope = $this->sidecarScope();
        $ceiling = (float) self::AMOUNT_CEILING;

        foreach (['seed_cost', 'weighted_avg_cost'] as $column) {
            /** @psalm-var mixed $max */
            $max = SubjectCost::maxWhere($column, $scope, [$from]);
            if (null === $max) {
                continue;
            }

            $projected = (float) $max * (float) $rate;
            if ($projected > $ceiling) {
                throw new CostConversionOverflowException($column, (string) $max, $rate, self::AMOUNT_CEILING);
            }
        }
    }
}
