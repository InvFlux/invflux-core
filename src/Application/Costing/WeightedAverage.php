<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Application\Costing;

/**
 * The moving-average arithmetic every cost-changing event shares: a receipt blending units in, and
 * a correction to a receipt revaluing or taking back what it brought.
 *
 * Pure — figures in, figures out — so a writer and a reader showing what a writer did run the same
 * function and cannot disagree. Amounts are in the store base. Results are 4-decimal strings, the
 * precision the average is stored at; float math, because the codebase has no bcmath, which is good
 * enough for valuation and worth revisiting if exact decimal accumulation is ever needed.
 *
 * Shipped units are never revalued (no retroaction): every function here works on the stock still
 * on hand, and what that stock cannot absorb is reported as a variance rather than forced into the
 * average.
 *
 * @api
 */
final class WeightedAverage
{
    /**
     * The average after a costed receipt: `(onHandBefore·priorCost + costedValue) / (onHandBefore + costedQty)`.
     *
     * With no prior cost, or no prior stock to weight it against (a short position counts as none),
     * the receipt's own unit cost (`costedValue / costedQty`) establishes the average. `$costedQty`
     * must be positive: uncosted units neither establish nor dilute an average, so a caller with none
     * has nothing to blend.
     */
    public static function blend(int $onHandBefore, ?string $priorCost, int $costedQty, float $costedValue): string
    {
        if (null === $priorCost || $onHandBefore <= 0) {
            return self::format($costedValue / (float) $costedQty);
        }

        return self::format(
            ((float) $onHandBefore * (float) $priorCost + $costedValue) / (float) ($onHandBefore + $costedQty),
        );
    }

    /**
     * The average after a receipt's figures for one product are corrected from `(qtyBefore, costBefore)`
     * to `(qtyAfter, costAfter)`, against the stock on hand now.
     *
     * 1. **Revalue** the receipt's units still here — at most `onHand` of them, since the rest have
     *    left at the cost they left at: the stock's value moves by `m·(costAfter − costBefore)`.
     * 2. **Move the difference** in quantity at the corrected cost: a removal takes its value out, an
     *    addition brings its value in.
     * 3. **If the stock absorbs the result** — units remain and their value is not negative — the
     *    average is that value over those units. **Otherwise the average stays**, and the part the
     *    stock could not take becomes the variance: value that already left with shipped units, or
     *    value that would sit on no stock at all.
     *
     * With nothing shipped since the receipt, steps 1 and 2 reproduce exactly the average a receipt
     * stating the corrected figures would have left.
     *
     * `$average` is null when the product has no average to correct (it was never costed); the result
     * then carries no cost and no variance, only the quantity.
     *
     * @param int         $onHand     total on hand now, before the correction moves anything
     * @param string|null $average    the product's average now
     * @param int         $qtyBefore  the receipt's costed units for the product, as it stands
     * @param float       $costBefore their unit cost, in base
     * @param int         $qtyAfter   the corrected costed units
     * @param float       $costAfter  their corrected unit cost, in base
     */
    public static function correct(
        int $onHand,
        ?string $average,
        int $qtyBefore,
        float $costBefore,
        int $qtyAfter,
        float $costAfter,
    ): CorrectedAverage {
        $onHandAfter = $onHand + ($qtyAfter - $qtyBefore);
        if (null === $average) {
            return new CorrectedAverage($onHandAfter, null, 0.0, true);
        }

        $stillHere = min(max($qtyBefore, 0), max($onHand, 0));
        $value = (float) max($onHand, 0) * (float) $average
            + (float) $stillHere * ($costAfter - $costBefore)
            + (float) ($qtyAfter - $qtyBefore) * $costAfter;

        if ($onHandAfter > 0 && $value >= 0.0) {
            return new CorrectedAverage($onHandAfter, self::format($value / (float) $onHandAfter), 0.0, true);
        }

        // The stock cannot take it: keep the average, and say how much did not fit.
        $variance = (float) max($onHandAfter, 0) * (float) $average - $value;

        return new CorrectedAverage($onHandAfter, $average, round($variance, 4), false);
    }

    private static function format(float $cost): string
    {
        return number_format($cost, 4, '.', '');
    }
}
