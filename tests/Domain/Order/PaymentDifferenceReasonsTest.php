<?php

declare(strict_types=1);

namespace Tests\Domain\Order;

use Nandan108\InvFlux\Domain\Order\OrderEventTier;
use Nandan108\InvFlux\Domain\Order\OrderEventType;
use Nandan108\InvFlux\Domain\Order\PaymentDifferenceReason;
use Nandan108\InvFlux\Domain\Order\PaymentDifferenceReasons;
use Nandan108\InvFlux\Exceptions\ConfigurationException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PaymentDifferenceReasonsTest extends TestCase
{
    public function testTheBuiltInsExplainTheirOwnSide(): void
    {
        $reasons = new PaymentDifferenceReasons();

        self::assertTrue($reasons->explainsShortfall(PaymentDifferenceReason::BANK_FEE));
        self::assertFalse($reasons->explainsExcess(PaymentDifferenceReason::BANK_FEE), 'a fee never makes a payment larger');
        self::assertTrue($reasons->explainsExcess(PaymentDifferenceReason::OVERPAID));
        self::assertFalse($reasons->explainsShortfall(PaymentDifferenceReason::OVERPAID));
        foreach ([PaymentDifferenceReason::FX, PaymentDifferenceReason::ROUNDING, PaymentDifferenceReason::OTHER] as $either) {
            self::assertTrue($reasons->explainsShortfall($either) && $reasons->explainsExcess($either), $either);
        }
    }

    public function testAnAddOnRegistersItsOwn(): void
    {
        $reasons = new PaymentDifferenceReasons();
        $reasons->register('early_payment_discount', shortfall: true, excess: false);

        self::assertTrue($reasons->has('early_payment_discount'));
        self::assertTrue($reasons->explainsShortfall('early_payment_discount'));
        self::assertFalse($reasons->explainsExcess('early_payment_discount'));
        self::assertSame('early_payment_discount', array_slice($reasons->all(), -1)[0] ?? null);
    }

    public function testAnUnknownReasonExplainsNothing(): void
    {
        $reasons = new PaymentDifferenceReasons();

        self::assertFalse($reasons->has('gift'));
        self::assertFalse($reasons->explainsShortfall('gift'));
        self::assertFalse($reasons->explainsExcess('gift'));
    }

    /**
     * @return iterable<string, array{string, bool, bool}>
     */
    public static function invalidRegistrations(): iterable
    {
        yield 'upper case' => ['BankFee', true, true];
        yield 'too long' => [str_repeat('a', 33), true, true];
        yield 'neither side' => ['nothing', false, false];
    }

    #[DataProvider('invalidRegistrations')]
    public function testRefuses(string $code, bool $shortfall, bool $excess): void
    {
        $this->expectException(ConfigurationException::class);

        (new PaymentDifferenceReasons())->register($code, $shortfall, $excess);
    }

    public function testPaymentEventsAreMonetary(): void
    {
        foreach ([OrderEventType::PaymentRecorded, OrderEventType::PaymentVoided] as $type) {
            self::assertTrue($type->isMonetary(), $type->value.' carries a MonetaryEventPayload');
            self::assertSame(OrderEventTier::Lifecycle, $type->tier());
        }
    }
}
