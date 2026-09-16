<?php

declare(strict_types=1);

namespace Tests\Domain\Order;

use Nandan108\Attrecord\Exception\RecordValidationException;
use Nandan108\InvFlux\Domain\Order\OrderCharge;
use Nandan108\InvFlux\Domain\Order\OrderChargeKind;
use PHPUnit\Framework\TestCase;

final class OrderChargeTest extends TestCase
{
    /** 16-byte binary id fixture. */
    private const ORDER_ID = "\x01\x02\x03\x04\x05\x06\x07\x08\x09\x0a\x0b\x0c\x0d\x0e\x0f\x10";

    public function testAcceptsASignedChargeOfAnyKind(): void
    {
        $charge = (new OrderCharge())->set([
            'order_id'     => self::ORDER_ID,
            'external_ref' => '8031',
            'kind'         => OrderChargeKind::Payment,
            'name'         => 'Payment fee',
            'amount'       => '-2.50',
            'tax'          => '0.00',
        ]);

        self::assertSame(OrderChargeKind::Payment, $charge->kind);
        self::assertSame('-2.50', $charge->amount, 'a discount recorded as a charge keeps its sign');
    }

    public function testAnUnclassifiedChargeIsOther(): void
    {
        self::assertSame(OrderChargeKind::Other, (new OrderCharge())->kind);
    }

    public function testRejectsAChargeWithNoSourceItem(): void
    {
        $this->expectException(RecordValidationException::class);
        $this->expectExceptionMessage('external_ref must identify the charge');

        (new OrderCharge())->set(['order_id' => self::ORDER_ID, 'external_ref' => '']);
    }

    public function testRejectsAWrongLengthOrderId(): void
    {
        $this->expectException(RecordValidationException::class);
        $this->expectExceptionMessage('order_id must be a 16-byte binary UUIDv7');

        (new OrderCharge())->set(['order_id' => 'short', 'external_ref' => '8031']);
    }

    public function testRejectsAnAmountThatIsNotANumber(): void
    {
        $this->expectException(RecordValidationException::class);
        $this->expectExceptionMessage('OrderCharge.tax must be a decimal amount');

        (new OrderCharge())->set(['order_id' => self::ORDER_ID, 'external_ref' => '8031', 'tax' => 'n/a']);
    }

    /** The export contract: every kind with a UNCL 7161 code maps to it, the two without map to none. */
    public function testKindsCarryTheirUnclCodes(): void
    {
        $codes = [];
        foreach (OrderChargeKind::cases() as $kind) {
            $codes[$kind->value] = $kind->unclCode();
        }

        self::assertSame([
            'freight'   => 'FC',
            'rush'      => 'AAT',
            'packing'   => 'PC',
            'handling'  => 'HD',
            'financing' => 'FI',
            'payment'   => null,
            'other'     => null,
        ], $codes);
    }
}
