<?php

declare(strict_types=1);

namespace Tests\Domain\Order;

use Nandan108\Attrecord\Exception\RecordValidationException;
use Nandan108\InvFlux\Domain\Order\OrderLine;
use PHPUnit\Framework\TestCase;

final class OrderLineTest extends TestCase
{
    /** 16-byte order UUID fixture. */
    private const ORDER_UUID = "\x01\x02\x03\x04\x05\x06\x07\x08\x09\x0a\x0b\x0c\x0d\x0e\x0f\x10";

    public function testValidLineConstructs(): void
    {
        $line = (new OrderLine())->set($this->validAttrs());

        $this->assertSame(self::ORDER_UUID, $line->order_id);
        $this->assertSame('456', $line->external_line_ref);
        $this->assertSame(2, $line->qty_ordered);
    }

    public function testInvalidOrderIdRejected(): void
    {
        $this->expectException(RecordValidationException::class);
        $this->expectExceptionMessage('order_id must be a 16-byte binary UUIDv7');

        (new OrderLine())->set([...$this->validAttrs(), 'order_id' => null]);
    }

    public function testEmptyExternalLineRefRejected(): void
    {
        $this->expectException(RecordValidationException::class);
        $this->expectExceptionMessage('external_line_ref must be a non-empty string');

        (new OrderLine())->set([...$this->validAttrs(), 'external_line_ref' => '']);
    }

    public function testInvalidSubjectIdRejected(): void
    {
        $this->expectException(RecordValidationException::class);
        $this->expectExceptionMessage('subject_id must be a positive integer');

        (new OrderLine())->set([...$this->validAttrs(), 'subject_id' => 0]);
    }

    public function testRefundIsThePricePaidNotTheListPrice(): void
    {
        // The case that bounced off the host: 129.00 listed, 19.35 off, 109.65 charged.
        $line = $this->priced(unitPrice: '129.00', discount: '19.35', ordered: 1);

        $this->assertSame('109.65', $line->refundFor(1));
        $this->assertSame('109.65', $line->netUnitPrice());
    }

    public function testUndiscountedLineRefundsAtItsListPrice(): void
    {
        $this->assertSame('34.00', $this->priced(unitPrice: '17.00', discount: '0.00', ordered: 2)->refundFor(2));
    }

    public function testPiecesAddUpToTheLineNeverACentMore(): void
    {
        // 100.01 over three units: rounding each unit refunds 33.34 × 3 = 100.02.
        $line = $this->priced(unitPrice: '40.00', discount: '19.99', ordered: 3);

        $pieces = [];
        for ($corrected = 0; $corrected < 3; ++$corrected) {
            $line->qty_corrected = $corrected;
            $pieces[] = $line->refundFor(1);
        }

        $this->assertSame(['33.34', '33.33', '33.34'], $pieces);
        $this->assertSame(10001, array_sum(array_map(static fn (string $p): int => (int) round((float) $p * 100.0), $pieces)));

        $line->qty_corrected = 0;
        $this->assertSame('100.01', $line->refundFor(3), 'all at once equals one at a time');
    }

    public function testRefundStopsAtWhatTheLineHasLeft(): void
    {
        $line = $this->priced(unitPrice: '10.00', discount: '0.00', ordered: 2);
        $line->qty_corrected = 1;

        $this->assertSame('10.00', $line->refundFor(5));
        $this->assertSame('0.00', $line->refundFor(0));
    }

    public function testADiscountLargerThanTheLineRefundsNothing(): void
    {
        $this->assertSame('0.00', $this->priced(unitPrice: '5.00', discount: '9.00', ordered: 1)->refundFor(1));
    }

    private function priced(string $unitPrice, string $discount, int $ordered): OrderLine
    {
        return (new OrderLine())->set([
            ...$this->validAttrs(),
            'unit_price'    => $unitPrice,
            'line_discount' => $discount,
            'qty_ordered'   => $ordered,
        ]);
    }

    /** @return array<string, mixed> */
    private function validAttrs(): array
    {
        return [
            'order_id'          => self::ORDER_UUID,
            'external_line_ref' => '456',
            'subject_id'        => 789,
            'name'              => 'Widget',
            'sku'               => 'W-001',
            'qty_ordered'       => 2,
        ];
    }
}
