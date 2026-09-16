<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Domain\Order;

use Nandan108\InvFlux\Exceptions\ConfigurationException;

/**
 * The payment-difference reasons an installation knows — {@see PaymentDifferenceReason}'s built-ins,
 * plus any an add-on registers — and which side of what an order owes each can explain.
 *
 * A writer checks a difference's reason here before recording it, so a payment never carries a reason
 * nothing registered, nor one that cannot explain its difference (a bank's fee never makes a payment
 * larger). Registration is open and idempotent: registering a code again replaces its sides.
 *
 * @api
 */
final class PaymentDifferenceReasons
{
    /** The column holding a reason is this wide. */
    public const MAX_LENGTH = 32;

    /** @var array<string, array{shortfall: bool, excess: bool}> */
    private array $reasons = [];

    public function __construct()
    {
        $this->reasons = PaymentDifferenceReason::builtIn();
    }

    /**
     * Register one reason.
     *
     * @param bool $shortfall whether it can explain a payment for less than was owed
     * @param bool $excess    whether it can explain a payment for more than was owed
     *
     * @throws ConfigurationException when the code is not a lower-case identifier of at most
     *                                {@see MAX_LENGTH} characters, or explains neither side
     */
    public function register(string $code, bool $shortfall = true, bool $excess = true): void
    {
        if (1 !== preg_match('/^[a-z][a-z0-9_]*$/', $code) || \strlen($code) > self::MAX_LENGTH) {
            throw new ConfigurationException(
                \sprintf('A payment difference reason must be a lower-case identifier of at most %d characters, got "%s".', self::MAX_LENGTH, $code),
                'invalid_payment_difference_reason',
            );
        }
        if (!$shortfall && !$excess) {
            throw new ConfigurationException(
                \sprintf('The payment difference reason "%s" must explain a shortfall, an excess, or both.', $code),
                'invalid_payment_difference_reason',
            );
        }

        $this->reasons[$code] = ['shortfall' => $shortfall, 'excess' => $excess];
    }

    public function has(string $code): bool
    {
        return isset($this->reasons[$code]);
    }

    /** Whether `$code` is registered and can explain a payment for less than was owed. */
    public function explainsShortfall(string $code): bool
    {
        return $this->reasons[$code]['shortfall'] ?? false;
    }

    /** Whether `$code` is registered and can explain a payment for more than was owed. */
    public function explainsExcess(string $code): bool
    {
        return $this->reasons[$code]['excess'] ?? false;
    }

    /** @return list<string> every registered reason, built-ins first, then in registration order */
    public function all(): array
    {
        return array_keys($this->reasons);
    }
}
