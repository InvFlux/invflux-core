<?php

declare(strict_types=1);

namespace Tests\Domain\Order;

use Nandan108\Attrecord\Exception\RecordValidationException;
use Nandan108\InvFlux\Domain\Order\OrderPayment;
use Nandan108\InvFlux\Domain\Order\PaymentSource;
use Nandan108\InvFlux\Identity\RecordIdentity;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class OrderPaymentTest extends TestCase
{
    /** 16-byte binary id fixture. */
    private const ORDER_ID = "\x01\x02\x03\x04\x05\x06\x07\x08\x09\x0a\x0b\x0c\x0d\x0e\x0f\x10";

    #[\Override]
    protected function setUp(): void
    {
        RecordIdentity::setMinter(static fn (): string => random_bytes(16));
    }

    public function testAPaymentInTheOrdersCurrencyCountsItsAmount(): void
    {
        $payment = $this->payment(['amount' => '120.50']);
        $payment->beforeSave();

        self::assertSame('120.50', $payment->amount_order_ccy);
        self::assertNotNull($payment->id);
        self::assertNotNull($payment->recorded_at);
    }

    public function testAPaymentInAnotherCurrencyCountsItsConvertedAmount(): void
    {
        $payment = $this->payment(['amount' => '100.00', 'currency' => 'EUR', 'fx_rate' => '0.93512345']);
        $payment->beforeSave();

        self::assertSame('93.51', $payment->amount_order_ccy);
    }

    public function testTheConvertedAmountIsRederivedOnEverySave(): void
    {
        $payment = $this->payment(['amount' => '50.00']);
        $payment->amount_order_ccy = '999.99';
        $payment->beforeSave();

        self::assertSame('50.00', $payment->amount_order_ccy, 'derived, so it cannot disagree with the amount and rate');
    }

    public function testAVoidStatesWhoWhenAndWhy(): void
    {
        $payment = $this->payment();
        $at = new \DateTimeImmutable('2026-09-14 10:00:00');

        $payment->void(7, '  Recorded against the wrong order ', $at);

        self::assertTrue($payment->isVoided());
        self::assertSame(7, $payment->voided_by);
        self::assertSame($at, $payment->voided_at);
        self::assertSame('Recorded against the wrong order', $payment->void_reason);
        $payment->validate();
    }

    public function testAVoidNeedsAReason(): void
    {
        $this->expectException(RecordValidationException::class);
        $this->expectExceptionMessage('void_reason must say why');

        $this->payment()->void(7, ' ', new \DateTimeImmutable());
    }

    public function testAPaymentIsVoidedOnce(): void
    {
        $payment = $this->payment();
        $payment->void(7, 'Mistake', new \DateTimeImmutable());

        $this->expectException(\LogicException::class);
        $payment->void(8, 'Again', new \DateTimeImmutable());
    }

    public function testThePaidTotalLeavesVoidedPaymentsOut(): void
    {
        $a = $this->payment(['amount' => '30.10']);
        $b = $this->payment(['amount' => '0.20']);
        $voided = $this->payment(['amount' => '500.00']);
        foreach ([$a, $b, $voided] as $payment) {
            $payment->beforeSave();
        }
        $voided->void(7, 'Mistake', new \DateTimeImmutable());

        self::assertSame('30.30', OrderPayment::paidTotal([$a, $b, $voided]));
        self::assertSame('0.00', OrderPayment::paidTotal([]));
    }

    public function testASettledDifferenceCountsTowardsWhatIsSettledNotWhatWasPaid(): void
    {
        $short = $this->payment(['amount' => '95.50', 'difference' => '4.50', 'difference_reason' => 'bank_fee']);
        $over = $this->payment(['amount' => '20.30', 'difference' => '-0.30', 'difference_reason' => 'rounding']);
        $voided = $this->payment(['amount' => '8.00', 'difference' => '2.00', 'difference_reason' => 'bank_fee']);
        foreach ([$short, $over, $voided] as $payment) {
            $payment->beforeSave();
        }
        $voided->void(7, 'Mistake', new \DateTimeImmutable());

        self::assertSame('100.00', $short->settledAmount());
        self::assertSame('120.00', OrderPayment::settledTotal([$short, $over, $voided]));
        self::assertSame('115.80', OrderPayment::paidTotal([$short, $over, $voided]), 'the money itself');
    }

    public function testADifferenceWithItsReasonIsAccepted(): void
    {
        $payment = $this->payment(['difference' => '4.50', 'difference_reason' => 'bank_fee']);

        self::assertSame('4.50', $payment->difference);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function invalidPayments(): iterable
    {
        yield 'wrong-length order id' => [['order_id' => 'short'], 'order_id must be a 16-byte binary UUIDv7'];
        yield 'zero amount' => [['amount' => '0.00'], 'amount must be a positive decimal amount'];
        yield 'negative amount' => [['amount' => '-5.00'], 'amount must be a positive decimal amount'];
        yield 'lower-case currency' => [['currency' => 'chf'], 'currency must be an ISO 4217 code'];
        yield 'zero rate' => [['fx_rate' => '0'], 'fx_rate must be a positive rate'];
        yield 'no method' => [['method' => ''], 'method must name the payment method'];
        yield 'no source' => [['source' => ''], 'source must be a registered payment source'];
        yield 'short shipment id' => [['shipment_id' => 'x'], 'shipment_id must be a 16-byte binary UUIDv7'];
        yield 'no received date' => [['received_at' => null], 'received_at must say when the money arrived'];
        yield 'unexplained difference' => [['difference' => '-1.00'], 'difference_reason must say why'];
        yield 'half a void' => [['voided_at' => new \DateTimeImmutable()], 'set together or not at all'];
    }

    /**
     * @param array<string, mixed> $override
     */
    #[DataProvider('invalidPayments')]
    public function testRejects(array $override, string $message): void
    {
        $this->expectException(RecordValidationException::class);
        $this->expectExceptionMessage($message);

        $this->payment($override);
    }

    /**
     * @param array<string, mixed> $override
     */
    private function payment(array $override = []): OrderPayment
    {
        return (new OrderPayment())->set($override + [
            'order_id'    => self::ORDER_ID,
            'amount'      => '10.00',
            'currency'    => 'CHF',
            'method'      => 'bacs',
            'source'      => PaymentSource::MANUAL,
            'received_at' => new \DateTimeImmutable('2026-09-13 09:30:00'),
        ]);
    }
}
