<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Domain\Order;

use Nandan108\InvFlux\Exceptions\ConfigurationException;

/**
 * The payment sources an installation knows — {@see PaymentSource}'s built-ins, plus any an add-on
 * registers.
 *
 * A writer checks its source here before recording a payment, so a payment never names a source
 * nothing registered. Registration is open and idempotent: registering a code twice is one code.
 *
 * @api
 */
final class PaymentSources
{
    /** The column holding a source is this wide. */
    public const MAX_LENGTH = 32;

    /** @var array<string, true> */
    private array $codes = [];

    public function __construct()
    {
        foreach (PaymentSource::builtIn() as $code) {
            $this->codes[$code] = true;
        }
    }

    /**
     * Register one source.
     *
     * @throws ConfigurationException when the code is not a lower-case identifier of at most
     *                                {@see MAX_LENGTH} characters
     */
    public function register(string $code): void
    {
        if (1 !== preg_match('/^[a-z][a-z0-9_]*$/', $code) || \strlen($code) > self::MAX_LENGTH) {
            throw new ConfigurationException(
                \sprintf('A payment source must be a lower-case identifier of at most %d characters, got "%s".', self::MAX_LENGTH, $code),
                'invalid_payment_source',
            );
        }

        $this->codes[$code] = true;
    }

    public function has(string $code): bool
    {
        return isset($this->codes[$code]);
    }

    /** @return list<string> every registered source, built-ins first, then in registration order */
    public function all(): array
    {
        return array_keys($this->codes);
    }
}
