<?php

declare(strict_types=1);

namespace Tests\Domain\Order;

use Nandan108\Attrecord\Exception\RecordValidationException;
use Nandan108\InvFlux\Domain\Order\MonetaryEventPayload;
use Nandan108\InvFlux\Domain\Order\OrderEvent;
use Nandan108\InvFlux\Domain\Order\OrderEventType;
use Nandan108\InvFlux\Util\Ulid;
use PHPUnit\Framework\TestCase;

final class OrderEventTest extends TestCase
{
    /** 16-byte order UUID fixture. */
    private const ORDER_UUID = "\x01\x02\x03\x04\x05\x06\x07\x08\x09\x0a\x0b\x0c\x0d\x0e\x0f\x10";

    public function testValidEventConstructs(): void
    {
        $event = (new OrderEvent())->set($this->validAttrs());

        $this->assertSame(self::ORDER_UUID, $event->order_id);
        $this->assertSame(OrderEventType::OrderCreated->value, $event->event_type);
    }

    public function testInvalidOrderIdRejected(): void
    {
        $this->expectException(RecordValidationException::class);
        $this->expectExceptionMessage('order_id must be a 16-byte binary UUIDv7');

        (new OrderEvent())->set([...$this->validAttrs(), 'order_id' => null]);
    }

    public function testUnknownEventTypeRejected(): void
    {
        $this->expectException(RecordValidationException::class);
        $this->expectExceptionMessage('event_type (not.a.thing) is not a known OrderEventType value');

        (new OrderEvent())->set([...$this->validAttrs(), 'event_type' => 'not.a.thing']);
    }

    public function testInvalidCorrelationIdRejected(): void
    {
        $this->expectException(RecordValidationException::class);
        $this->expectExceptionMessage('correlation_id must be a valid ULID when set');

        (new OrderEvent())->set([...$this->validAttrs(), 'correlation_id' => 'not-a-ulid']);
    }

    public function testValidUlidCorrelationIdAccepted(): void
    {
        $event = (new OrderEvent())->set([...$this->validAttrs(), 'correlation_id' => Ulid::generate()]);
        $this->assertNotNull($event->correlation_id);
    }

    public function testNullCorrelationIdAccepted(): void
    {
        $event = (new OrderEvent())->set($this->validAttrs());
        $this->assertNull($event->correlation_id);
    }

    public function testMonetaryEventTypeWithoutMonetaryPayloadRejected(): void
    {
        $this->expectException(RecordValidationException::class);
        $this->expectExceptionMessage('payload for monetary type RefundFailed must be a MonetaryEventPayload');

        (new OrderEvent())->set([
            ...$this->validAttrs(),
            'event_type' => OrderEventType::RefundFailed->value,
            'payload'    => ['correction_ids' => []],
        ]);
    }

    public function testMonetaryEventTypeWithMonetaryPayloadAccepted(): void
    {
        $payload = new MonetaryEventPayload(
            amount: '10.00',
            currency: 'EUR',
            base_amount: '10.00',
            base_currency: 'EUR',
            fx_rate_used: '1.00000000',
            extras: ['correction_ids' => ['abc']],
        );
        $event = (new OrderEvent())->set([
            ...$this->validAttrs(),
            'event_type' => OrderEventType::RefundFailed->value,
            'payload'    => $payload,
        ]);
        $this->assertSame($payload, $event->payload);
    }

    public function testNonMonetaryEventTypeAcceptsArrayPayload(): void
    {
        $event = (new OrderEvent())->set([
            ...$this->validAttrs(),
            'payload' => ['source_system' => 'woo', 'external_id' => '123'],
        ]);
        $this->assertIsArray($event->payload);
    }

    /** @return array<string, mixed> */
    private function validAttrs(): array
    {
        return [
            'order_id'   => self::ORDER_UUID,
            'event_type' => OrderEventType::OrderCreated->value,
        ];
    }
}
