<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Domain\Procurement;

/**
 * What one purchase line costs per unit: the net price, and — when the supplier stated one — the list
 * price and percentage discount it was reached from.
 *
 * **The net is the price.** Receipt valuation, weighted-average cost, variances, line totals and the
 * order's cached total all read `unit_cost`, which is always the net. The list price and discount are
 * recorded beside it because suppliers quote that way ("20.00 less 10%") and the order document
 * shows it back to them that way; they explain the net and never replace it as the figure anything
 * else computes from. So a line carries either all three or the net alone — a list price without a
 * discount, or a discount without a list price, states nothing the net does not.
 *
 * **The net is computed here, once.** `net = list × (1 − discount / 100)`, rounded half-up *per unit*
 * to the decimals the supplier prices in — never more than the column's four — so the net is a price
 * the supplier could have quoted; a line total is that unit net × quantity, never a discount taken
 * off the total. The arithmetic is exact — integers at the columns' own scales — because a float
 * would put a different last decimal into stock value depending on how the list price happened to
 * be written.
 *
 * Each `with*()` method is one kind of edit, and the rules are what make a line coherent whichever
 * field an operator touches:
 *
 * - **a net price typed directly replaces the discount** — the operator has stated the answer;
 * - **a discount comes off a price** — the list price given with it, else the line's own list price,
 *   else its current net, else a fallback (the catalogue price an unpriced line inherits);
 * - **clearing the discount keeps the list price as the net** — the price before the discount is what
 *   is left once there is no discount;
 * - **a list price alone keeps the discount** it already has, recomputing the net; with no discount it
 *   simply is the price.
 *
 * @api
 */
final class LinePrice
{
    /** Decimal places of a per-unit price column (`DECIMAL(10,4)`). */
    private const PRICE_SCALE = 4;

    /** Decimal places of a discount column (`DECIMAL(5,2)`). */
    private const PCT_SCALE = 2;

    /** The discount that would give the goods away; every discount must stay below it. */
    private const PCT_CEILING = 100;

    public function __construct(
        public readonly ?string $net,
        public readonly ?string $list = null,
        public readonly ?string $discountPct = null,
    ) {
    }

    /** A typed net price: it stands on its own, whatever discount the line carried. */
    public function withNet(?string $net): self
    {
        return new self(null === $net ? null : self::price($net));
    }

    /**
     * A discount, or `null` to remove it.
     *
     * @param int         $decimals the decimals the supplier prices in; the net is rounded to them
     * @param string|null $base     the price to take the discount off, when the same edit states one
     * @param string|null $fallback the price an unpriced line inherits (a catalogue price)
     *
     * @throws \InvalidArgumentException when a discount has no price to come off, or is out of range
     */
    public function withDiscount(?string $discountPct, int $decimals, ?string $base = null, ?string $fallback = null): self
    {
        if (null === $discountPct) {
            // The price before the discount is what is left; with no discount to remove, nothing moves.
            $left = $base ?? $this->list;

            return null === $left ? new self($this->net) : new self(self::price($left));
        }
        $from = $base ?? $this->list ?? $this->net ?? $fallback;
        if (null === $from) {
            throw new \InvalidArgumentException('A discount needs a price to come off.');
        }

        $list = self::price($from);
        $pct = self::discount($discountPct);

        return new self(self::netOf($list, $pct, $decimals), $list, $pct);
    }

    /**
     * A list price. Keeps the discount already on the line; with none, the list price is the price.
     * `null` removes the list price, and with it any discount — the net stays as it is.
     *
     * @param int $decimals the decimals the supplier prices in; a recomputed net is rounded to them
     */
    public function withList(?string $list, int $decimals): self
    {
        if (null === $list) {
            return new self($this->net);
        }
        if (null === $this->discountPct) {
            return new self(self::price($list));
        }

        return $this->withDiscount($this->discountPct, $decimals, $list);
    }

    /** Whether this price was reached through a discount the supplier stated. */
    public function isDiscounted(): bool
    {
        return null !== $this->discountPct;
    }

    /**
     * The net unit price for a list price and a percentage discount, rounded half-up to `$decimals`
     * places — the supplier's precision, capped at the column's — and written at the column's scale.
     *
     * @throws \InvalidArgumentException when either is not a number in range
     */
    public static function netOf(string $list, string $discountPct, int $decimals): string
    {
        $decimals = max(0, min(self::PRICE_SCALE, $decimals));
        $listUnits = self::scaled($list, self::PRICE_SCALE);
        $pctUnits = self::scaled(self::discount($discountPct), self::PCT_SCALE);
        $whole = self::PCT_CEILING * 10 ** self::PCT_SCALE;
        // `list × (whole − pct) / whole` is the net in the column's units; dividing by a further
        // 10^(4 − decimals) lands it on the supplier's precision.
        $step = (int) (10 ** (self::PRICE_SCALE - $decimals));
        $divisor = $whole * $step;

        // Half-up on a non-negative product; both factors are bounded by their columns, so the product
        // stays far inside a 64-bit integer (10^10 × 10^4).
        return self::format(intdiv($listUnits * ($whole - $pctUnits) + intdiv($divisor, 2), $divisor) * $step, self::PRICE_SCALE);
    }

    /**
     * Whether a stored triple is coherent: the net alone, or a list price and discount whose net, at
     * some precision up to the column's, is the stored one. Compared at the columns' scales, so `"18"`
     * and `"18.0000"` agree.
     *
     * Any precision, because the precision is the supplier's and not the line's to know — and a
     * supplier's precision can change after a line was priced, which must not turn a line priced
     * correctly at the time into an invalid one.
     */
    public static function isCoherent(?string $net, ?string $list, ?string $discountPct): bool
    {
        if (null === $list && null === $discountPct) {
            return true;
        }
        if (null === $list || null === $discountPct || null === $net) {
            return false;
        }
        try {
            $stored = self::scaled($net, self::PRICE_SCALE);
            for ($decimals = 0; $decimals <= self::PRICE_SCALE; ++$decimals) {
                if (self::scaled(self::netOf($list, $discountPct, $decimals), self::PRICE_SCALE) === $stored) {
                    return true;
                }
            }

            return false;
        } catch (\InvalidArgumentException) {
            return false;
        }
    }

    /** A non-negative price, normalised to the price column's scale. */
    private static function price(string $value): string
    {
        return self::format(self::scaled($value, self::PRICE_SCALE), self::PRICE_SCALE);
    }

    /** A discount from 0 up to (not including) 100, normalised to the discount column's scale. */
    private static function discount(string $value): string
    {
        $units = self::scaled($value, self::PCT_SCALE);
        if ($units >= self::PCT_CEILING * 10 ** self::PCT_SCALE) {
            throw new \InvalidArgumentException('A discount must be below 100%.');
        }

        return self::format($units, self::PCT_SCALE);
    }

    /**
     * A non-negative decimal string as an integer count of `10^-$scale` units, rounded half-up past
     * the scale. Parsed as text so no float ever touches the value.
     */
    private static function scaled(string $value, int $scale): int
    {
        $value = trim($value);
        if (1 !== preg_match('/^(\d*)(?:\.(\d*))?$/', $value, $m) || '' === ($m[1].($m[2] ?? ''))) {
            throw new \InvalidArgumentException(sprintf('Not a non-negative number: "%s".', $value));
        }
        $fraction = $m[2] ?? '';
        $kept = str_pad(substr($fraction, 0, $scale), $scale, '0');
        $units = (int) (('' === $m[1] ? '0' : $m[1]).$kept);
        if (\strlen($fraction) > $scale && (int) $fraction[$scale] >= 5) {
            ++$units;
        }

        return $units;
    }

    private static function format(int $units, int $scale): string
    {
        $divisor = 10 ** $scale;

        return intdiv($units, $divisor).'.'.str_pad((string) ($units % $divisor), $scale, '0', STR_PAD_LEFT);
    }
}
