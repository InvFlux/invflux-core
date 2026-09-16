<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Domain\Order;

/**
 * How far a payment may fall short of what an order owes, or exceed it, and still settle it.
 *
 * Two limits, the smaller governing: an absolute amount, and optionally a percentage of what is owed.
 * Zero — the default — is exact. A payment settled within the tolerance records the difference and why
 * it was accepted ({@see OrderPayment::$difference}); a tolerance never makes a difference silent.
 *
 * Amounts are decimal strings in the currency of what is owed.
 *
 * @api
 */
final class PaymentTolerance
{
    /**
     * @param string      $absolute the largest difference accepted, as an amount
     * @param string|null $percent  the largest difference accepted, as a percentage of what is owed;
     *                              null for no percentage limit
     *
     * @throws \InvalidArgumentException when a limit is not a non-negative number, or the percentage
     *                                   exceeds 100
     */
    public function __construct(
        public readonly string $absolute = '0.00',
        public readonly ?string $percent = null,
    ) {
        if (!is_numeric($absolute) || (float) $absolute < 0.0) {
            throw new \InvalidArgumentException(\sprintf('A payment tolerance must be a non-negative amount, got "%s".', $absolute));
        }
        if (null !== $percent && (!is_numeric($percent) || (float) $percent < 0.0 || (float) $percent > 100.0)) {
            throw new \InvalidArgumentException(\sprintf('A payment tolerance percentage must be between 0 and 100, got "%s".', $percent));
        }
    }

    public static function exact(): self
    {
        return new self();
    }

    /** The largest difference accepted against `$owed`. The percentage limit rounds down. */
    public function allowedFor(string $owed): string
    {
        return self::fromCents($this->allowedCents(self::toCents($owed)));
    }

    /** Whether `$received` settles `$owed` — equal to it, or within the tolerance either side. */
    public function accepts(string $owed, string $received): bool
    {
        $owedCents = self::toCents($owed);

        return abs(self::toCents($received) - $owedCents) <= $this->allowedCents($owedCents);
    }

    private function allowedCents(int $owedCents): int
    {
        $allowed = self::toCents($this->absolute);
        if (null !== $this->percent) {
            $allowed = min($allowed, (int) floor((float) abs($owedCents) * (float) $this->percent / 100.0));
        }

        return $allowed;
    }

    private static function toCents(string $amount): int
    {
        return (int) round((float) $amount * 100.0);
    }

    private static function fromCents(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
    }
}
