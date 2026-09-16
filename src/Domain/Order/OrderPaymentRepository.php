<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Domain\Order;

/**
 * Persistence boundary for {@see OrderPayment} — the payments received against an order.
 *
 * Concrete implementations live in storage adapters. Reads return voided payments too: the list is
 * the order's payment history, and a void is part of it. Sum what counts with
 * {@see OrderPayment::paidTotal()}.
 *
 * @api
 */
interface OrderPaymentRepository
{
    /** @param string $id 16-byte binary UUIDv7 */
    public function findById(string $id): ?OrderPayment;

    /**
     * Every payment on one order, voided ones included, in the order they were recorded.
     *
     * @param string $orderId 16-byte binary UUIDv7
     *
     * @return list<OrderPayment>
     */
    public function forOrder(string $orderId): array;

    /**
     * Every payment on the given orders, in one read.
     *
     * @param list<string> $orderIds 16-byte binary UUIDv7s; an empty list reads nothing
     *
     * @return list<OrderPayment>
     */
    public function forOrders(array $orderIds): array;

    /**
     * Insert or update many payments in a single bulk operation — never a per-row save loop. An
     * update is how a void is written; nothing else about a payment changes after it is recorded.
     *
     * @param list<OrderPayment> $payments
     *
     * @return list<OrderPayment> the same records, with ids populated
     */
    public function saveAll(array $payments): array;
}
