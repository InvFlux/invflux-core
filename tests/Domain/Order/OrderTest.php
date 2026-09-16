<?php

declare(strict_types=1);

namespace Tests\Domain\Order;

use Nandan108\Attrecord\Exception\RecordValidationException;
use Nandan108\InvFlux\Domain\Order\HoldReason;
use Nandan108\InvFlux\Domain\Order\Order;
use Nandan108\InvFlux\Domain\Order\OrderStatus;
use Nandan108\InvFlux\Domain\Order\OrderWorkflowState;
use PHPUnit\Framework\TestCase;

final class OrderTest extends TestCase
{
    public function testValidOrderConstructs(): void
    {
        $order = (new Order())->set($this->validAttrs());

        $this->assertSame('woo', $order->source_system);
        $this->assertSame('12345', $order->external_id);
        $this->assertSame(OrderStatus::Untouched, $order->status);
        // Workflow state is derived: a fresh order has no hold/close dimension → Active.
        $this->assertSame(OrderWorkflowState::Active, $order->workflowState());
    }

    public function testWorkflowStateDerivationPrecedence(): void
    {
        $terminal = new \DateTimeImmutable('2026-01-01 00:00:00');

        // Two system dimensions only (parking is now a tag, not a dimension here).
        $this->assertSame(OrderWorkflowState::Active, OrderWorkflowState::derive(null, null));
        $this->assertSame(OrderWorkflowState::OnHold, OrderWorkflowState::derive(HoldReason::PaymentPending, null));
        $this->assertSame(OrderWorkflowState::Closed, OrderWorkflowState::derive(null, $terminal));
        // Closed dominates OnHold.
        $this->assertSame(OrderWorkflowState::Closed, OrderWorkflowState::derive(HoldReason::PaymentPending, $terminal));
    }

    public function testEmptySourceSystemRejected(): void
    {
        $this->expectException(RecordValidationException::class);
        $this->expectExceptionMessage('source_system must be a non-empty string');

        (new Order())->set([...$this->validAttrs(), 'source_system' => '']);
    }

    public function testEmptyExternalIdRejected(): void
    {
        $this->expectException(RecordValidationException::class);
        $this->expectExceptionMessage('external_id must be a non-empty string');

        (new Order())->set([...$this->validAttrs(), 'external_id' => '']);
    }

    // An invalid status is not a runtime validation concern — the property is enum-typed
    // (#[EnumCaster(OrderStatus::class)]), so a non-enum value is rejected by the type system at assignment.

    /** @return array<string, mixed> */
    private function validAttrs(): array
    {
        return [
            'source_system'  => 'woo',
            'external_id'    => '12345',
            'status'         => OrderStatus::Untouched,
            'line_count'     => 2,
            'customer_id'    => 7,
            'customer_name'  => 'Jane Doe',
            'customer_email' => 'jane@example.com',
        ];
    }
}
