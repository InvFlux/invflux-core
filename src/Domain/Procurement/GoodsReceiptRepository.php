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

    /** One receipt, by id — null when there is none. */
    public function findGoodsReceipt(int $id): ?GoodsReceipt;

    /**
     * Every receipt that answers one document — the purchase order, or an add-on's document — oldest
     * first. The document reads its receipts from here rather than from its own event log, so an
     * order whose events predate any receipt reference still lists them.
     *
     * @return list<GoodsReceipt>
     */
    public function goodsReceiptsForSource(int $sourceRefTypeId, int $sourceId): array;

    /**
     * Which of these receipts have been corrected, and what each now totals: good and damaged units
     * as its lines stand after the latest correction of each. A receipt whose totals are both zero was
     * reversed, though it stays on file.
     *
     * One batched read for a list, never a query per row: a list of receipts is scanned, and its
     * figures would otherwise cost a query each.
     *
     * @param list<int> $receiptIds
     *
     * @return array<int, array{qty: int, damaged: int}> keyed by receipt id, present only for a
     *                                                   receipt with at least one correction
     */
    public function correctedReceiptTotals(array $receiptIds): array;

    /**
     * The exchange rate the most recent receipt against an order in `$currency` was converted at —
     * what a new receipt in that currency is offered as a starting point, never applied unseen.
     * Null when no receipt in that currency has recorded one.
     *
     * @param int $poRefTypeId the reference type a receipt names a purchase order by
     *
     * @return numeric-string|null
     */
    public function lastFxRateForCurrency(string $currency, int $poRefTypeId): ?string;

    /**
     * A receipt's lines, in the order they were written.
     *
     * @return list<ReceiptLine>
     */
    public function goodsReceiptLines(int $receiptId): array;

    /**
     * The corrections recorded against a receipt, oldest first.
     *
     * @return list<ReceiptCorrection>
     */
    public function receiptCorrections(int $receiptId): array;

    /**
     * Every line of every correction to a receipt, in the order they were written — so the last
     * one per receipt line is that line's current figures.
     *
     * @return list<ReceiptCorrectionLine>
     */
    public function receiptCorrectionLines(int $receiptId): array;
}
