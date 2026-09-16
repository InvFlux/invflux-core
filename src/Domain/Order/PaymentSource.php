<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Domain\Order;

/**
 * The payment sources core itself writes — where an {@see OrderPayment} came from.
 *
 * These constants do **not** close the set. A source is a registered value, validated against
 * {@see PaymentSources}, never against this class: an add-on that collects money through a channel
 * of its own (a courier collecting cash on delivery) registers its own source, and core never
 * learns the word. The same reason slot states are registered values rather than a constant class.
 *
 * @api
 */
final class PaymentSource
{
    /** The payment gateway confirmed it; copied in from the source system. */
    public const GATEWAY = 'gateway';

    /** An operator recorded it by hand — a bank transfer or cheque seen arriving. */
    public const MANUAL = 'manual';

    /**
     * Inferred from the source system's order status: an administrator marked an order paid in the
     * host without a gateway confirming it, on a payment method whose policy says that means paid.
     */
    public const HOST_STATUS = 'host_status';

    /** @return list<string> */
    public static function builtIn(): array
    {
        return [self::GATEWAY, self::MANUAL, self::HOST_STATUS];
    }
}
