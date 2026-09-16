<?php

declare(strict_types=1);

namespace Tests\Domain\Order;

use Nandan108\Attrecord\Exception\RecordValidationException;
use Nandan108\InvFlux\Domain\Order\OrderCorrection;
use Nandan108\InvFlux\Util\Ulid;
use PHPUnit\Framework\TestCase;

final class OrderCorrectionTest extends TestCase
{
    /** 16-byte order UUID fixture (same value for every test). */
    private const ORDER_UUID = "\x01\x02\x03\x04\x05\x06\x07\x08\x09\x0a\x0b\x0c\x0d\x0e\x0f\x10";

    /** 16-byte line UUID fixture. */
    private const LINE_UUID = "\x10\x0f\x0e\x0d\x0c\x0b\x0a\x09\x08\x07\x06\x05\x04\x03\x02\x01";

    public function testValidCorrectionConstructs(): void
    {
        $correction = (new OrderCorrection())->set($this->validAttrs());

        $this->assertSame(self::ORDER_UUID, $correction->order_id);
        $this->assertSame(3, $correction->type_id);
        $this->assertSame(5, $correction->reason_id);
        $this->assertTrue($correction->isUnprocessed());
    }

    public function testNullReasonIdAccepted(): void
    {
        $correction = (new OrderCorrection())->set([...$this->validAttrs(), 'reason_id' => null]);
        $this->assertNull($correction->reason_id);
    }

    public function testProcessedFlagDerivedFromTimestamp(): void
    {
        $correction = (new OrderCorrection())->set([...$this->validAttrs(), 'processed_at' => new \DateTimeImmutable()]);
        $this->assertFalse($correction->isUnprocessed());
    }

    public function testInvalidOrderIdRejected(): void
    {
        $this->expectException(RecordValidationException::class);
        $this->expectExceptionMessage('order_id must be a 16-byte binary UUIDv7');

        (new OrderCorrection())->set([...$this->validAttrs(), 'order_id' => null]);
    }

    public function testInvalidLineIdRejected(): void
    {
        $this->expectException(RecordValidationException::class);
        $this->expectExceptionMessage('line_id must be a 16-byte binary UUIDv7');

        (new OrderCorrection())->set([...$this->validAttrs(), 'line_id' => null]);
    }

    public function testInvalidTypeIdRejected(): void
    {
        $this->expectException(RecordValidationException::class);
        $this->expectExceptionMessage('type_id must be a positive integer');

        (new OrderCorrection())->set([...$this->validAttrs(), 'type_id' => 0]);
    }

    public function testZeroReasonIdRejected(): void
    {
        $this->expectException(RecordValidationException::class);
        $this->expectExceptionMessage('reason_id must be null or a positive integer');

        (new OrderCorrection())->set([...$this->validAttrs(), 'reason_id' => 0]);
    }

    public function testNegativeQtyRejected(): void
    {
        $this->expectException(RecordValidationException::class);
        $this->expectExceptionMessage('qty must be non-negative');

        (new OrderCorrection())->set([...$this->validAttrs(), 'qty' => -1]);
    }

    public function testNonUlidCorrelationIdRejected(): void
    {
        // The bug this guards: a writer used correlation_id as a semantic idempotency tag
        // ('foreign_refund:{id}'). It fits CHAR(26), so it stored fine and only failed later,
        // when OrderEvent copied it and validated it — inside a hook that swallowed the throw,
        // so a WooCommerce-side refund silently skipped its stock movement.
        $this->expectException(RecordValidationException::class);
        $this->expectExceptionMessage('correlation_id must be a valid ULID when set');

        (new OrderCorrection())->set([...$this->validAttrs(), 'correlation_id' => 'foreign_refund:42']);
    }

    public function testUlidCorrelationIdAndArbitraryIdempotencyKeyAccepted(): void
    {
        $correction = (new OrderCorrection())->set([
            ...$this->validAttrs(),
            'correlation_id'  => Ulid::generate(),
            // Naming the operation is what the separate key is for — no format constraint.
            'idempotency_key' => 'foreign_refund:42',
        ]);

        self::assertSame('foreign_refund:42', $correction->idempotency_key);
    }

    /** @return array<string, mixed> */
    private function validAttrs(): array
    {
        return [
            'order_id'      => self::ORDER_UUID,
            'line_id'       => self::LINE_UUID,
            'type_id'       => 3,
            'reason_id'     => 5,
            'qty'           => 2,
            'refund_amount' => '10.00',
            'created_by'    => 0,
        ];
    }
}
