<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Domain\Procurement;

/**
 * Stage-neutral classification of a PO-line quantity variance — an `actual` measured against a
 * `baseline`. Universal across the PO lifecycle: the same four cases describe a *confirmation*
 * variance (ordered vs supplier-confirmed, at submit/in-transit) and a *delivery* variance
 * (received vs {@see VarianceLens}, at reception). The Essentials "flag short/over, don't block" signal.
 *
 * Purely derived (no stored state) via {@see self::classify()}; the {@see PurchaseOrderLine}
 * accessors pick the stage-appropriate `actual`/`baseline`/`finalized` triple.
 *
 * The `Over`/`Match` split and the `Under` sign are universal; `Under` refines into **Open** (still
 * filling — more is expected) vs **Short** (finalized below baseline, e.g. a close-short) only where
 * the quantity accrues progressively (reception). A non-progressive axis (confirmation) is always
 * `finalized`, so its under case is `Short`.
 *
 * The over-receipt *tolerance guard* (warn/override on an anomalous over-delivery) is not modelled
 * here: at Essentials the operator is the guard and this classification is the display. The structured,
 * policy-driven guard is Pro (supplier-% + per-SKU override), with automated/scanner/parallel
 * receiving in Pro / Scale-Micro.
 *
 * @api
 */
enum VarianceStatus: string
{
    /** `actual` equals `baseline` — no variance. */
    case Match = 'match';

    /** `actual` is below `baseline` but not finalized — still expecting more (in progress). */
    case Open = 'open';

    /** `actual` exceeds `baseline` (always a discrepancy). */
    case Over = 'over';

    /** `actual` is finalized below `baseline` (e.g. a close-short, or a confirmed shortfall). */
    case Short = 'short';

    /**
     * Classify `actual` against `baseline`. `finalized` marks whether the quantity can still grow: on a
     * progressive axis (reception) it is false while units are still expected and true once closed;
     * on a non-progressive axis (confirmation) it is always true. An under-baseline value is `Open`
     * while unfinalized, `Short` once finalized.
     */
    public static function classify(int $baseline, int $actual, bool $finalized): self
    {
        if ($actual > $baseline) {
            return self::Over;
        }
        if ($actual < $baseline) {
            return $finalized ? self::Short : self::Open;
        }

        return self::Match;
    }

    /** Whether this status is a discrepancy the merchant may want to act on (over or finalized-short). */
    public function isDiscrepancy(): bool
    {
        return self::Over === $this || self::Short === $this;
    }
}
