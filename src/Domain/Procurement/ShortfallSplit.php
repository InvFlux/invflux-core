<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Domain\Procurement;

/**
 * How what is still open on a line splits into {@see ShortfallPart}s when it is closed short — from
 * the counts, never from the operator. Pure.
 *
 * Of the open quantity (ordered − good received − closed short):
 * - `unconfirmed` is what the supplier never confirmed: ordered − confirmed, where a confirmation is
 *   recorded below the order;
 * - `damaged` is the damaged units, which arrived but are not stock and so are still open;
 * - `undelivered` is the rest: confirmed, and not here.
 *
 * Each is capped by what is left, so the parts always add up to the open quantity. `in_transit` is
 * never produced here: it needs a shipped quantity, which only an add-on's shipping notice gives.
 *
 * @api
 */
final class ShortfallSplit
{
    /**
     * @return array<value-of<ShortfallPart>, positive-int> part → quantity, in part order, parts
     *                                                      with nothing omitted
     */
    public static function forLine(PurchaseOrderLine $line): array
    {
        return self::split(
            max(0, $line->qty_open),
            $line->qty_requested ?? 0,
            $line->qty_expected,
            $line->qty_damaged,
        );
    }

    /**
     * @return array<value-of<ShortfallPart>, positive-int>
     */
    public static function split(int $open, int $ordered, ?int $confirmed, int $damaged): array
    {
        $unconfirmed = min($open, max(0, $ordered - ($confirmed ?? $ordered)));
        $damagedPart = min($open - $unconfirmed, max(0, $damaged));
        $undelivered = $open - $unconfirmed - $damagedPart;

        $out = [];
        foreach ([
            ShortfallPart::Unconfirmed->value => $unconfirmed,
            ShortfallPart::Undelivered->value => $undelivered,
            ShortfallPart::Damaged->value     => $damagedPart,
        ] as $part => $qty) {
            if ($qty > 0) {
                $out[$part] = $qty;
            }
        }

        return $out;
    }
}
