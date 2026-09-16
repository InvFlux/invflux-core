<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Domain\Procurement;

use Nandan108\InvFlux\Domain\Subject\SubjectId;

/**
 * Repository contract for the purchase-order aggregate. Implemented by a storage adapter
 * (`invflux-storage-mysql` MysqlDomainStore); procurement use-cases depend on this, not
 * the concrete store.
 *
 * @api
 */
interface PurchaseOrderRepository extends DocumentPartyRepository
{
    /**
     * Create a PO as an un-numbered draft: persist the row (its surrogate `id` is assigned here),
     * leaving `number` NULL. The gapless document number is minted later, at the Assign-number
     * action, via {@see assignPurchaseOrderNumber()} — never at create.
     */
    public function createPurchaseOrder(PurchaseOrder $po): PurchaseOrder;

    /**
     * Mint and assign the gapless document number for a PO that has none, atomically: lock the
     * scheme's counter row (`SELECT … FOR UPDATE`), format the next value, increment the counter, set
     * `number`, and persist the PO together with its {@see PoEvent} in one transaction. **Idempotent:**
     * a PO that already carries a number is returned unchanged — the counter is not advanced and no
     * event is written, so a re-click just re-uses the same number. The caller supplies the event to
     * record on a fresh mint.
     */
    public function assignPurchaseOrderNumber(PurchaseOrder $po, PoNumberScheme $scheme, PoEvent $event): PurchaseOrder;

    /** Persist edits to an existing PO (does not touch the number). */
    public function savePurchaseOrder(PurchaseOrder $po): PurchaseOrder;

    /**
     * Apply a validated status transition atomically: persist the PO's new status and append
     * the transition's {@see PoEvent} in one transaction. The legality of the transition is the
     * caller's responsibility (see {@see \Nandan108\InvFlux\Application\Procurement\TransitionPurchaseOrder});
     * this method only guarantees the status change and its audit event commit together.
     */
    public function transitionPurchaseOrder(PurchaseOrder $po, PoEvent $event): PurchaseOrder;

    /**
     * Persist a header edit (e.g. a revised ETA, tax rate) together with its audit {@see PoEvent} in one
     * transaction — same atomic "change + event" guarantee as {@see transitionPurchaseOrder}, but without
     * any status semantics. The event records what changed (the OrderEvents principle: the history is the
     * trail of events, so a revised ETA is recoverable from the `po.eta_changed` payloads).
     */
    public function updatePurchaseOrderHeader(PurchaseOrder $po, PoEvent $event): PurchaseOrder;

    public function findPurchaseOrder(int $id): ?PurchaseOrder;

    /**
     * Several purchase orders at once, keyed by id and omitting ids that name none.
     *
     * The bulk form of {@see self::findPurchaseOrder()}, for a listing that has a set of ids in hand
     * — resolving them one at a time is a query per row of a list built to be scanned.
     *
     * @param list<int> $ids
     *
     * @return array<int, PurchaseOrder>
     */
    public function purchaseOrdersByIds(array $ids): array;

    /** @return list<PurchaseOrder> */
    public function listPurchaseOrders(): array;

    /**
     * Purchase orders currently in one of the given statuses, newest first.
     *
     * The receiving surface's question — "which orders can I count against?" — asked of the whole
     * table rather than filtered afterwards, so a store with thousands of finished orders does not
     * read them all to show the handful still arriving.
     *
     * @param list<PoStatus> $statuses
     *
     * @return list<PurchaseOrder>
     */
    public function purchaseOrdersInStatus(array $statuses, int $limit = 100): array;

    /**
     * Number of lines on each PO, keyed by po id — one `GROUP BY`, never a per-PO query. POs with
     * no lines are simply absent from the map.
     *
     * @param list<int> $poIds
     *
     * @return array<int, int>
     */
    public function lineCountsForPurchaseOrders(array $poIds): array;

    /**
     * Per-PO **delivery-discrepancy** rollup for the list view — the number of over- and
     * finalized-short lines on each PO, one `GROUP BY` (never a per-PO query). Measured against the
     * *ordered* baseline (the records/admin lens): `over` = `qty_received > qty_requested`; `short` =
     * `qty_received < qty_requested` on a finalized line (`qty_open = 0`, i.e. closed-short). This is a
     * set-based SQL mirror of {@see VarianceStatus::classify()} under {@see VarianceLens::Ordered}
     * — kept in SQL to avoid hydrating every line just for a list badge. POs with no discrepancy
     * are absent from the map.
     *
     * @param list<int> $poIds
     *
     * @return array<int, array{over: int, short: int}>
     */
    public function deliveryDiscrepancyRollup(array $poIds): array;

    /**
     * PO counts per supplier, grouped by status: `supplier_id => (status => count)`. One `GROUP BY`
     * over all POs; the caller buckets into open / draft / closed.
     *
     * @return array<int, array<int, int>>
     */
    public function statusCountsBySupplier(): array;

    /**
     * Add / persist one or more PO lines in a single bulk operation (one INSERT for new
     * rows, one deadlock-safe upsert for keyed rows) — never a per-line save loop.
     *
     * @param list<PurchaseOrderLine> $lines
     *
     * @return list<PurchaseOrderLine> the same records, with ids populated
     */
    public function savePurchaseOrderLines(array $lines): array;

    /** Hard-delete a single PO line (draft editing — removing a line before the PO is sent). */
    public function deletePurchaseOrderLine(PurchaseOrderLine $line): void;

    /**
     * Count the **non-draft, non-terminal** purchase orders that reference a subject — the
     * deletion guard's `open_procurement` check. An open PO can still move quantity (it can be
     * received, or received-but-not-yet-closed), so any status other than draft (in-prep),
     * cancelled, or archived counts. Draft POs commit nothing and are excluded here (they are
     * instead scrubbed by {@see removeSubjectFromDraftPurchaseOrders()} on a clean delete).
     * One aggregate query joining lines to their POs; distinct PO count.
     */
    public function countOpenProcurementForSubject(SubjectId $subjectId): int;

    /**
     * Remove a subject's lines from every **draft** (in-prep) purchase order — the procurement
     * half of the clean-delete cleanup (§5.6). A draft PO commits nothing, so a subject can be
     * referenced there without raising an `open_procurement` blocker; when the subject is
     * legitimately deleted, those dangling draft references are scrubbed. Non-draft POs never
     * reach this path — they would have blocked the delete. Returns the number of lines removed.
     * One set-based `DELETE`, never a per-line loop.
     */
    public function removeSubjectFromDraftPurchaseOrders(SubjectId $subjectId): int;

    /** @return list<PurchaseOrderLine> */
    public function linesForPurchaseOrder(int $poId): array;

    /**
     * Record a goods receipt against a PO in one transaction: persist the receipt + its
     * lines (each line's `receipt_id` is assigned here) and bump every referenced PO
     * line's `qty_received` cache. The stock ledger movement (`po_receipt`, `nil → oh.atp`)
     * and the WAC update layer on top of this — they are NOT done here.
     *
     * @param list<ReceiptLine> $lines
     */
    public function recordReceipt(GoodsReceipt $receipt, array $lines): GoodsReceipt;

    /** @return list<GoodsReceipt> */
    public function receiptsForPurchaseOrder(int $poId): array;

    /**
     * Record a supplier invoice against a PO in one transaction: persist the invoice + its lines
     * (each line's `invoice_id` is assigned here) and write back the two cached fields the ordered
     * lines carry — `qty_invoiced` and `unit_cost_invoiced`.
     *
     * The caller computes those two, because what they mean differs: the quantity accumulates across
     * invoices, the rate is replaced by the latest. This method only has to make the document and the
     * write-back land together, so a recorded invoice can never leave the order quoting a rate no
     * document supports.
     *
     * @param list<SupplierInvoiceLine> $lines
     * @param list<PurchaseOrderLine>   $touchedOrderLines already carrying their updated cached fields
     */
    public function recordSupplierInvoice(
        SupplierInvoice $invoice,
        array $lines,
        array $touchedOrderLines,
    ): SupplierInvoice;

    /**
     * Every invoice recorded against a PO, oldest first, with `lines` loaded.
     *
     * @return list<SupplierInvoice>
     */
    public function supplierInvoicesForPurchaseOrder(int $poId): array;

    /**
     * The invoice lines already recorded against these ordered lines, keyed by `po_line_id`.
     *
     * What {@see \Nandan108\InvFlux\Application\Procurement\RecordSupplierInvoice} accumulates
     * `qty_invoiced` from: a running total kept in one place would drift the moment an invoice was
     * corrected or removed, so it is summed from the documents that justify it.
     *
     * @param list<int> $poLineIds
     *
     * @return array<int, list<SupplierInvoiceLine>>
     */
    public function supplierInvoiceLinesForOrderLines(array $poLineIds): array;

    /**
     * The PO's audit/history events (lifecycle transitions, receipts, close-shorts, …), oldest first —
     * the source for the activity timeline.
     *
     * @return list<PoEvent>
     */
    public function poEventsForPurchaseOrder(int $poId): array;

    /**
     * Append a standalone (non-transition) audit event — a receipt logged, a document attached, etc.
     * (transition events go through {@see transitionPurchaseOrder()} so the status + event commit as one).
     */
    public function recordPoEvent(PoEvent $event): PoEvent;

    /**
     * The registry id of the `purchase_order` ref type — what a goods receipt stores to say it
     * answers an order, rather than arriving with no document behind it.
     *
     * A receipt's source is polymorphic ({@see GoodsReceipt}), so the *kind* of document is named by
     * registry id and not by a column dedicated to one table. Callers that build a receipt against an
     * order need that id, and resolving it is the store's job because the store is what seeds the
     * registry. Null on an installation whose reference seed has not run.
     */
    public function purchaseOrderRefTypeId(): ?int;
}
