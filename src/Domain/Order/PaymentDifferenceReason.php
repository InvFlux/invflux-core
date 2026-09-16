<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Domain\Order;

/**
 * Why a payment that differs from what an order owes still settles it — the codes core itself knows
 * ({@see OrderPayment::$difference_reason}).
 *
 * **Each reason implies a different posting**, which is why a difference always carries one and why
 * the codes are stable: a fee the bank deducted on the way is a bank charge, an exchange-rate
 * difference is a gain or a loss, rounding has its own small account, and money paid beyond what was
 * owed is a credit held for the customer until it is refunded or kept. Core records the reason; an
 * accounting add-on maps it to its ledgers.
 *
 * These constants do **not** close the set: a reason is a registered value
 * ({@see PaymentDifferenceReasons}), so an add-on can register its own.
 *
 * @api
 */
final class PaymentDifferenceReason
{
    /** The bank or payment service deducted its fee on the way: less arrived than was sent. */
    public const BANK_FEE = 'bank_fee';

    /** Converting between currencies left a difference. */
    public const FX = 'fx';

    /** A difference of rounding. */
    public const ROUNDING = 'rounding';

    /** The customer paid more than was owed; the excess is theirs until it is refunded or kept. */
    public const OVERPAID = 'overpaid';

    /** Anything else — the operator's note says what. */
    public const OTHER = 'other';

    /**
     * The built-in reasons, each with the side of what is owed it can explain: `shortfall` (less
     * arrived) and `excess` (more arrived).
     *
     * @return array<string, array{shortfall: bool, excess: bool}>
     */
    public static function builtIn(): array
    {
        return [
            self::BANK_FEE => ['shortfall' => true, 'excess' => false],
            self::FX       => ['shortfall' => true, 'excess' => true],
            self::ROUNDING => ['shortfall' => true, 'excess' => true],
            self::OVERPAID => ['shortfall' => false, 'excess' => true],
            self::OTHER    => ['shortfall' => true, 'excess' => true],
        ];
    }
}
