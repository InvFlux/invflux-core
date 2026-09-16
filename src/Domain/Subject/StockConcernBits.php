<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Domain\Subject;

/**
 * Integer bit vocabulary for {@see SubjectStockConcern::$bits}, for the raw-int SQL predicates that
 * read the column directly (`BIT_OR(bits)`, `(bits & X) <> 0`). {@see StockConcern} is the typed
 * source of truth for the values; this class is the int-alias layer.
 *
 * The constants are written as literals rather than as `StockConcern::Case->value`, because a
 * property fetch is not a valid constant expression before PHP 8.2 and this package supports 8.1
 * (the PrestaShop adapter's host floor). The correspondence is therefore checked rather than
 * compiled: `StockConcernTest` asserts every constant here equals its enum case, and fails if a
 * case is added, removed or renumbered without this class following.
 *
 * **The concern vocabulary is core-owned and closed.** All bits are core-managed (a bitmask caster
 * requires one static enum to own every bit). Supplementary third-party / adapter-specific flags are
 * **not** bits here — they are carried by tags — so there is no adapter-extensible bit range.
 *
 * **All bits are subject-level.** Per-order risks (promise breach,
 * priority allocation) live on the order, not here. A concern is
 * eligible for a bit only if its firing condition is a property of the
 * subject and applies identically to every order line referencing the
 * subject.
 *
 * **Tier-gated population, stable vocabulary.** Every constant here
 * exists in core regardless of license tier — the drainer needs the
 * full bit space to round-trip data written by any tier. Higher-tier
 * bits are *populated* only when the drainer step that knows how to
 * compute them is enabled (via `LicenseGate::allows()`). The bit
 * positions never shift across tiers.
 *
 * Layout: `0x01 – 0x8000` (the column is `SMALLINT UNSIGNED`, 16 bits). Six bits defined today; the
 * rest are reserved for future core concerns, not adapter extension — supplementary flags are
 * tags, and the vocabulary is closed.
 *
 * @api
 */
final class StockConcernBits
{
    /**
     * Global stock deficit: `ctd_qty < sum(qty_outstanding)` for this
     * subject across all unshipped orders. Fires only in failure modes
     * (stock take adjustment, correction/sale race, external desync,
     * oversell); `ctd_qty` is invariant-equal to `sum(qty_outstanding)`
     * by construction in healthy operation. Every line of a deficit-
     * affected subject carries the bit; the merchant chooses the winner
     * via the Stock Issue workflow (Pro) or manual reallocation.
     *
     * **Populated at:** Essentials.
     */
    public const BIT_STOCK_DEFICIT = 0x01;

    /**
     * The source-system identifier the subject was resolved against is
     * no longer active. WC adapter case: the underlying product post
     * was trashed. ERP adapter case: the SKU was flagged `Inactive`.
     * Generic: any adapter signals the subject is no longer a "live"
     * SKU in its catalog, yet open orders still reference it.
     *
     * Surfaces the "you're about to ship something you deleted last
     * month — is that still the right call?" prompt.
     *
     * **Populated at:** Essentials.
     */
    public const BIT_SUBJECT_INACTIVE = 0x02;

    /**
     * Subject is on a merchant-set quality / recall hold. Ship blocked
     * until the hold is released. Distinct from
     * {@see BIT_BATCH_EXPIRED}: a hold reflects a *deliberate*
     * decision to stop shipping (recall risk, awaiting QC, regulatory
     * pause), whereas expiry is a passive timeline state.
     *
     * **Populated at:** Pro (the hold-management UI ships at Pro).
     */
    public const BIT_QUALITY_HOLD = 0x04;

    /**
     * Batch tracking is enabled for this subject and every batch that
     * could cover the outstanding demand is past expiry. Ship blocked
     * without an admin override.
     *
     * Pairs with {@see BIT_BATCH_EXPIRY_RISK} — the at-risk state still
     * allows shipping (FEFO-routed); the expired state doesn't. The
     * two are split into separate bits because the badge UX is louder
     * for expired (red, blocking) than for at-risk (amber, hint).
     *
     * **Populated at:** Pro (batch tracking is a Pro feature).
     */
    public const BIT_BATCH_EXPIRED = 0x08;

    /**
     * Batch tracking is enabled and at least one available batch falls
     * within the configurable expiry-risk window (e.g. expires within
     * 7 days of estimated dispatch). Ship still possible but the
     * picker should route through the shortest-shelf-life batch (FEFO).
     *
     * **Populated at:** Pro.
     */
    public const BIT_BATCH_EXPIRY_RISK = 0x10;

    /**
     * Multi-warehouse redistribution needed: globally satisfiable
     * (`sum(global ctd) >= sum(global qty_outstanding)`), but at least
     * one warehouse can't cover its allocated obligation:
     *
     *   ∃ whX: whX.ctd < sum(qty_outstanding allocated to whX)
     *
     * The fix is to redirect an order's origin warehouse — which may
     * change the carrier, ETA, or customer-facing promise.
     * `BIT_STOCK_DEFICIT` doesn't fire in this state because the global
     * sum is healthy.
     *
     * **Populated at:** Scale (single-warehouse stores can't be in this
     * state by definition).
     */
    public const BIT_LOC_AT_RISK = 0x20;

    // 0x40, 0x80 — reserved for future core bits.
    // 0x100+      — adapter-extensible (e.g. WC-only stock-status drift
    //                from postmeta, ERP-driven freeze flags).

    /** Bitmask of every concern currently defined here. */
    public const ALL_CORE_BITS =
        self::BIT_STOCK_DEFICIT
        | self::BIT_SUBJECT_INACTIVE
        | self::BIT_QUALITY_HOLD
        | self::BIT_BATCH_EXPIRED
        | self::BIT_BATCH_EXPIRY_RISK
        | self::BIT_LOC_AT_RISK;

    /**
     * @psalm-suppress UnusedConstructor — private to enforce the
     *   static-constants-only contract; never called.
     */
    private function __construct()
    {
        // Static constants only.
    }
}
