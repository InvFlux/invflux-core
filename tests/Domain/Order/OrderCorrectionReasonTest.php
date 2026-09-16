<?php

declare(strict_types=1);

namespace Tests\Domain\Order;

use Nandan108\Attrecord\Exception\RecordValidationException;
use Nandan108\InvFlux\Domain\Order\Cause;
use Nandan108\InvFlux\Domain\Order\OrderCorrectionReason;
use PHPUnit\Framework\TestCase;

final class OrderCorrectionReasonTest extends TestCase
{
    public function testValidReasonConstructs(): void
    {
        $reason = (new OrderCorrectionReason())->set([
            'code'  => 'defective',
            'name'  => 'Defective from origin',
            'cause' => Cause::Merchant,
        ]);

        $this->assertNull($reason->id);
        $this->assertSame('defective', $reason->code);
        $this->assertSame(Cause::Merchant, $reason->cause);
    }

    public function testEmptyCodeRejected(): void
    {
        $this->expectException(RecordValidationException::class);
        $this->expectExceptionMessage('code must be a non-empty string');

        (new OrderCorrectionReason())->set(['code' => '', 'name' => 'Empty', 'cause' => Cause::Customer]);
    }

    public function testEmptyNameRejected(): void
    {
        $this->expectException(RecordValidationException::class);
        $this->expectExceptionMessage('name must be a non-empty string');

        (new OrderCorrectionReason())->set(['code' => 'defective', 'name' => '', 'cause' => Cause::Merchant]);
    }

    // An invalid `cause` is not a runtime validation concern — the property is enum-typed
    // (#[EnumCaster(Cause::class)]), so a non-Cause value is rejected by the type system at assignment.
}
