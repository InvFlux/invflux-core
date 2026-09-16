<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Domain\Procurement;

/**
 * Reading goods receipts as documents in their own right — what arrived, when, and against what.
 *
 * Separate from {@see PurchaseOrderRepository::receiptsForPurchaseOrder()}, which answers "what has
 * this order received"; these answer "what has this warehouse received", across sources and
 * including the receipts that answer no document at all. That is the receiving surface's history:
 * a reception in progress leaves no record when it is abandoned ({@see ReceivingSession}), so the
 * receipts *are* the history.
 *
 * @api
 */
interface GoodsReceiptRepository
{
    /**
     * The most recently received receipts, newest first.
     *
     * Ordered by `received_at` rather than by id: the two normally agree, but the timestamp is the
     * fact the operator is looking for, and a backdated import would sort wrongly under the id.
     *
     * @return list<GoodsReceipt>
     */
    public function recentGoodsReceipts(int $limit = 25, int $offset = 0): array;

    /**
     * How much each of these receipts brought in — line count, good units, damaged units.
     *
     * One grouped read for the whole page rather than a query per row: a receipts list that asked
     * per receipt would issue a query per row of a list whose only purpose is to be scanned.
     *
     * @param list<int> $receiptIds
     *
     * @return array<int, array{lines: int, qty: int, damaged: int}> keyed by receipt id; a receipt
     *                                                               with no lines is absent
     */
    public function goodsReceiptLineTotals(array $receiptIds): array;
}
