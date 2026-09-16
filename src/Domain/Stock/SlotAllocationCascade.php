<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Domain\Stock;

/**
 * Core-owned allocator for the commercial slot invariant.
 *
 * **Invariant:** when the committed (`ctd`) slot is in deficit — physical committed stock is
 * below the demand it must cover — neither `atp` (for-sale) nor `res` (reserved) may be
 * positive. Essentials and reserved stock only exist once committed demand is fully backed. Every
 * flow that changes on-hand availability must maintain this, or the system surfaces phantom
 * sellable stock (an oversell) while orders go silently under-backed.
 *
 * This class is the single home for the priority rule that maintains it. It is **pure** (no
 * I/O, no engine) — the caller reads the live breakdown + demand, calls one of these methods,
 * then issues the resulting per-slot movements. Platform-agnostic (core), so every adapter and
 * every stock flow shares one invariant rather than re-deriving it per flow.
 *
 * Priority:
 * - **Drain** (loss / damage / theft, a negative on-hand delta): `atp → res → ctd`
 *   (least- to most-committed) — only bites into carts, then committed orders, once the loss
 *   exceeds free stock. Floors at 0 per slot.
 * - **Fill** (found / recount-up / returned goods, a positive on-hand delta): fill the `ctd`
 *   deficit first (up to `soldQty`, restoring fulfillability), then the `res` deficit, then
 *   overflow to `atp`. With no deficit it all lands on `atp`.
 * - **Release from `ctd`** (a cancellation freed committed units whose demand is gone): only the
 *   surplus above the remaining demand may move to `atp`; in deficit nothing moves (the
 *   cancellation just shrinks the deficit — `atp` stays 0, invariant held).
 *
 * @api
 */
final class SlotAllocationCascade
{
    /**
     * Allocate a **total on-hand** delta across the commercial slots (`atp` / `res` / `ctd`).
     *
     * @param int $delta       requested total on-hand change (may exceed what's achievable)
     * @param int $atp         current for-sale quantity
     * @param int $res         current reserved (in-checkout) quantity
     * @param int $ctd         current committed (sold-not-dispatched) quantity
     * @param int $soldQty     outstanding committed demand the `ctd` slot should cover (deficit target)
     * @param int $reservedQty reservation demand the `res` slot should cover (deficit target)
     *
     * @return array{atp: int, res: int, ctd: int} per-slot deltas (sum = achievable portion of $delta)
     */
    public static function allocate(
        int $delta,
        int $atp,
        int $res,
        int $ctd,
        int $soldQty = 0,
        int $reservedQty = 0,
    ): array {
        if ($delta < 0) {
            return self::drain(-$delta, $atp, $res, $ctd);
        }
        if ($delta > 0) {
            return self::fill($delta, $res, $ctd, $soldQty, $reservedQty);
        }

        return ['atp' => 0, 'res' => 0, 'ctd' => 0];
    }

    /**
     * Units of a `ctd`-committed quantity that may move to `atp` when their demand is cancelled.
     *
     * A cancellation frees `qty` units currently in `ctd` and removes their demand. The cap exists
     * only to protect a `ctd` **deficit** from surfacing phantom sellable stock.
     *
     * **The invariant is the gate.** `ctd` deficit ⇒ `atp = res = 0`, so a positive `atp` or `res`
     * *proves* there is no deficit — every committed unit is already backed — and the full freed
     * `qty` may return to `atp`. This is deliberately keyed off the reliable slot state rather than
     * the demand count (which a stale/unbooked order can inflate into a phantom deficit). Only when
     * `atp = res = 0` can a real deficit exist; there, cap at the `ctd` surplus over the remaining
     * demand (`ctd <= demand` ⇒ 0, the cancellation just shrinks the deficit, `atp` stays 0). `res`
     * is never a restock target on the way up, so freed committed stock goes only to `atp` or nowhere.
     *
     * @param int $qty    committed units freed by the cancellation
     * @param int $atp    current for-sale quantity
     * @param int $res    current reserved quantity
     * @param int $ctd    current committed slot quantity
     * @param int $demand committed demand still owed **after** this cancellation (i.e. excluding it)
     *
     * @return int units to move `ctd → atp` (0 ≤ result ≤ qty)
     */
    public static function releasableFromCtd(int $qty, int $atp, int $res, int $ctd, int $demand): int
    {
        if ($atp > 0 || $res > 0) {
            return $qty; // positive atp/res ⇒ no deficit ⇒ the full freed qty is safe to surface
        }

        return max(0, min($qty, $ctd - $demand));
    }

    /**
     * Negative cascade: take `amount` from atp, then res, then ctd — never below 0.
     *
     * @return array{atp: int, res: int, ctd: int}
     */
    private static function drain(int $amount, int $atp, int $res, int $ctd): array
    {
        $takeAtp = min($amount, $atp);
        $amount -= $takeAtp;
        $takeRes = min($amount, $res);
        $amount -= $takeRes;
        $takeCtd = min($amount, $ctd);

        return ['atp' => -$takeAtp, 'res' => -$takeRes, 'ctd' => -$takeCtd];
    }

    /**
     * Positive cascade: fill the ctd deficit, then the res deficit, then overflow to atp.
     *
     * @return array{atp: int, res: int, ctd: int}
     */
    private static function fill(int $amount, int $res, int $ctd, int $soldQty, int $reservedQty): array
    {
        $toCtd = min($amount, max(0, $soldQty - $ctd));
        $amount -= $toCtd;
        $toRes = min($amount, max(0, $reservedQty - $res));
        $amount -= $toRes;
        $toAtp = $amount; // remainder overflows to available

        return ['atp' => $toAtp, 'res' => $toRes, 'ctd' => $toCtd];
    }
}
