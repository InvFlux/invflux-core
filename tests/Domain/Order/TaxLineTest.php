<?php

declare(strict_types=1);

namespace Tests\Domain\Order;

use Nandan108\Attrecord\Exception\RecordValidationException;
use Nandan108\InvFlux\Domain\Order\TaxLine;
use PHPUnit\Framework\TestCase;

final class TaxLineTest extends TestCase
{
    /** 16-byte binary id fixture. */
    private const PARENT_ID = "\x01\x02\x03\x04\x05\x06\x07\x08\x09\x0a\x0b\x0c\x0d\x0e\x0f\x10";

    public function testAcceptsKnownParentTypeWithBinaryParentId(): void
    {
        $line = (new TaxLine())->set([
            'parent_type' => 'correction',
            'parent_id'   => self::PARENT_ID,
            'tax_code'    => 'rate-1',
            'net'         => '10.00',
            'tax_rate'    => '19.0000',
            'tax_amount'  => '1.90',
        ]);

        $this->assertSame('correction', $line->parent_type);
        $this->assertSame(self::PARENT_ID, $line->parent_id);
        $this->assertSame('rate-1', $line->tax_code);
        $this->assertSame('10.00', $line->net);
    }

    public function testRejectsUnknownParentType(): void
    {
        $this->expectException(RecordValidationException::class);
        $this->expectExceptionMessage('parent_type "shipment" is not in KNOWN_PARENT_TYPES');

        (new TaxLine())->set([
            'parent_type' => 'shipment',
            'parent_id'   => self::PARENT_ID,
            'tax_code'    => 'rate-1',
        ]);
    }

    public function testRejectsEmptyParentType(): void
    {
        $this->expectException(RecordValidationException::class);
        $this->expectExceptionMessage('parent_type ""');

        (new TaxLine())->set([
            'parent_type' => '',
            'parent_id'   => self::PARENT_ID,
            'tax_code'    => 'rate-1',
        ]);
    }

    public function testRejectsWrongLengthParentId(): void
    {
        $this->expectException(RecordValidationException::class);
        $this->expectExceptionMessage('parent_id must be a 16-byte binary UUIDv7');

        (new TaxLine())->set([
            'parent_type' => 'correction',
            'parent_id'   => 'short',
            'tax_code'    => 'rate-1',
        ]);
    }

    public function testRejectsEmptyTaxCode(): void
    {
        $this->expectException(RecordValidationException::class);
        $this->expectExceptionMessage('tax_code must be non-empty');

        (new TaxLine())->set([
            'parent_type' => 'correction',
            'parent_id'   => self::PARENT_ID,
            'tax_code'    => '',
        ]);
    }
}
