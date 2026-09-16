<?php

declare(strict_types=1);

namespace Tests\Domain\Order;

use Nandan108\Attrecord\Exception\RecordValidationException;
use Nandan108\InvFlux\Domain\Order\OrderCorrectionType;
use PHPUnit\Framework\TestCase;

final class OrderCorrectionTypeTest extends TestCase
{
    public function testValidTypeConstructs(): void
    {
        $type = (new OrderCorrectionType())->set([
            'code'         => 'cancel_system',
            'name'         => 'System cancelled',
            'pre_dispatch' => true,
            'restock'      => true,
            'refund'       => true,
        ]);

        $this->assertNull($type->id);
        $this->assertSame('cancel_system', $type->code);
        $this->assertTrue($type->pre_dispatch);
        $this->assertTrue($type->restock);
        $this->assertTrue($type->refund);
    }

    public function testEmptyCodeRejected(): void
    {
        $this->expectException(RecordValidationException::class);
        $this->expectExceptionMessage('code must be a non-empty string');

        (new OrderCorrectionType())->set(['code' => '', 'name' => 'Empty']);
    }

    public function testEmptyNameRejected(): void
    {
        $this->expectException(RecordValidationException::class);
        $this->expectExceptionMessage('name must be a non-empty string');

        (new OrderCorrectionType())->set(['code' => 'cancel_system', 'name' => '']);
    }
}
