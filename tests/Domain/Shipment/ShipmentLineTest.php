<?php

declare(strict_types=1);

namespace Tests\Domain\Shipment;

use Nandan108\Attrecord\Exception\RecordValidationException;
use Nandan108\InvFlux\Domain\Shipment\CostBasis;
use Nandan108\InvFlux\Domain\Shipment\ShipmentLine;
use PHPUnit\Framework\TestCase;

final class ShipmentLineTest extends TestCase
{
    /** 16-byte UUID fixtures. */
    private const SHIPMENT_UUID = "\x11\x12\x13\x14\x15\x16\x17\x18\x19\x1a\x1b\x1c\x1d\x1e\x1f\x20";
    private const ORDER_UUID = "\x21\x22\x23\x24\x25\x26\x27\x28\x29\x2a\x2b\x2c\x2d\x2e\x2f\x30";

    public function testValidLineConstructs(): void
    {
        $line = (new ShipmentLine())->set([
            ...$this->validAttrs(),
            'qty_shipped'   => 3,
            'unit_cost'     => '12.5000',
            'cost_basis'    => CostBasis::Wac,
            'cost_currency' => 'EUR',
        ]);

        $this->assertSame(self::SHIPMENT_UUID, $line->shipment_id);
        $this->assertSame(self::ORDER_UUID, $line->order_id);
        $this->assertSame(7, $line->order_line_id);
        $this->assertSame(3, $line->qty_shipped);
        $this->assertSame('12.5000', $line->unit_cost);
        $this->assertSame(CostBasis::Wac, $line->cost_basis);
        $this->assertSame('EUR', $line->cost_currency);
    }

    public function testUncostedLineAccepted(): void
    {
        // No WAC + no seed cost → uncosted: cost fields stay null (never zero).
        $line = (new ShipmentLine())->set($this->validAttrs());

        $this->assertNull($line->unit_cost);
        $this->assertNull($line->cost_basis);
        $this->assertNull($line->cost_currency);
    }

    public function testNullShipmentIdAcceptedAtConstruction(): void
    {
        // The repository assigns shipment_id at persist time; null is fine before then.
        $line = (new ShipmentLine())->set([...$this->validAttrs(), 'shipment_id' => null]);
        $this->assertNull($line->shipment_id);
    }

    public function testMalformedShipmentIdRejected(): void
    {
        $this->expectException(RecordValidationException::class);
        $this->expectExceptionMessage('shipment_id must be a 16-byte binary UUIDv7');

        (new ShipmentLine())->set([...$this->validAttrs(), 'shipment_id' => 'short']);
    }

    public function testUnallocatedOrderLineIdRejected(): void
    {
        $this->expectException(RecordValidationException::class);
        $this->expectExceptionMessage('order_line_id must be a positive order-line number');

        (new ShipmentLine())->set([...$this->validAttrs(), 'order_line_id' => 0]);
    }

    /**
     * The order half of the reference is not optional either. It exists so the line reference can
     * be a real constraint — a foreign key names columns on this row and cannot reach through
     * `shipment_id` to find the order — so a row without it is a reference that cannot be checked.
     */
    public function testMalformedOrderIdRejected(): void
    {
        $this->expectException(RecordValidationException::class);
        $this->expectExceptionMessage('order_id must be a 16-byte binary UUIDv7');

        (new ShipmentLine())->set([...$this->validAttrs(), 'order_id' => 'short']);
    }

    // An invalid cost_basis is not a runtime validation concern — ShipmentLine.cost_basis is
    // enum-typed (#[EnumCaster(CostBasis::class)]), so a non-CostBasis value is rejected by the type system.

    public function testFifoCostBasisAccepted(): void
    {
        $line = (new ShipmentLine())->set([...$this->validAttrs(), 'cost_basis' => CostBasis::Fifo]);
        $this->assertSame(CostBasis::Fifo, $line->cost_basis);
    }

    /** @return array<string, mixed> */
    private function validAttrs(): array
    {
        return [
            'shipment_id'   => self::SHIPMENT_UUID,
            'order_id'      => self::ORDER_UUID,
            'order_line_id' => 7,
            'subject_id'    => 42,
        ];
    }
}
