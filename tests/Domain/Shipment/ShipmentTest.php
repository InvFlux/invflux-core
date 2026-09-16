<?php

declare(strict_types=1);

namespace Tests\Domain\Shipment;

use Nandan108\Attrecord\Exception\RecordValidationException;
use Nandan108\InvFlux\Domain\Shipment\Shipment;
use Nandan108\InvFlux\Domain\Shipment\ShipmentStatus;
use PHPUnit\Framework\TestCase;

final class ShipmentTest extends TestCase
{
    /** 16-byte order UUID fixture. */
    private const ORDER_UUID = "\x01\x02\x03\x04\x05\x06\x07\x08\x09\x0a\x0b\x0c\x0d\x0e\x0f\x10";

    public function testValidShipmentConstructs(): void
    {
        $shipment = (new Shipment())->set($this->validAttrs());

        $this->assertSame(self::ORDER_UUID, $shipment->order_id);
        // Default status is the pre-dispatch draft.
        $this->assertSame(ShipmentStatus::Saved, $shipment->status);
        $this->assertFalse($shipment->manual_tracking);
    }

    public function testProcessedStatusAccepted(): void
    {
        $shipment = (new Shipment())->set([
            ...$this->validAttrs(),
            'status' => ShipmentStatus::Processed,
        ]);
        $this->assertSame(ShipmentStatus::Processed, $shipment->status);
    }

    public function testInvalidOrderIdRejected(): void
    {
        $this->expectException(RecordValidationException::class);
        $this->expectExceptionMessage('order_id must be a 16-byte binary UUIDv7');

        (new Shipment())->set([...$this->validAttrs(), 'order_id' => null]);
    }

    public function testShortOrderIdRejected(): void
    {
        $this->expectException(RecordValidationException::class);
        $this->expectExceptionMessage('order_id must be a 16-byte binary UUIDv7');

        (new Shipment())->set([...$this->validAttrs(), 'order_id' => 'too-short']);
    }

    // An invalid status is not a runtime validation concern — Shipment.status is enum-typed
    // (#[EnumCaster(ShipmentStatus::class)]), so a non-ShipmentStatus value is rejected by the type system.

    /** @return array<string, mixed> */
    private function validAttrs(): array
    {
        return ['order_id' => self::ORDER_UUID];
    }
}
