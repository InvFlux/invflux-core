<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Domain\Procurement;

/**
 * Repository contract for reception work in progress. Implemented by a storage adapter
 * (`invflux-storage-mysql` MysqlDomainStore).
 *
 * Deliberately its own contract rather than methods on {@see PurchaseOrderRepository}. A session
 * belongs to the act of receiving, not to the purchase order that occasioned it — a receipt can
 * answer a shipment notice, or no document at all — and a contract that said otherwise would keep
 * asserting the coupling that {@see ReceivingSession} exists to remove.
 *
 * @api
 */
interface ReceivingSessionRepository
{
    /**
     * The open session against a purchase order, or null when nobody has staged anything.
     *
     * Takes a PO id rather than a ref-type/id pair so that resolving the `purchase_order` ref type
     * stays inside the store, exactly as {@see PurchaseOrderRepository::receiptsForPurchaseOrder()}
     * does. A caller that had to look the discriminator up itself would be reimplementing storage.
     */
    public function receivingSessionForPurchaseOrder(int $poId): ?ReceivingSession;

    /**
     * The open sessions for many purchase orders at once, keyed by PO id and omitting those with
     * none — the bulk form, for a listing that would otherwise ask once per row.
     *
     * @param list<int> $poIds
     *
     * @return array<int, ReceivingSession>
     */
    public function receivingSessionsForPurchaseOrders(array $poIds): array;

    /**
     * A session by its own id — the way a reception with no source document is addressed, since
     * such sessions share a null ref and so cannot be told apart by what they point at.
     */
    public function findReceivingSession(int $id): ?ReceivingSession;

    /**
     * Every reception currently in progress, newest activity first.
     *
     * The receiving surface's landing list: what is being counted right now, and by whom. Every row
     * in the table *is* an open session — a session is deleted when the receipt built from it is
     * committed, so "open" is the absence of a status column rather than a value in one.
     *
     * @param int $limit hard ceiling on the rows returned; a store with more receptions open than
     *                   this has a housekeeping problem the list cannot fix by growing
     *
     * @return list<ReceivingSession>
     */
    public function openReceivingSessions(int $limit = 100): array;

    /** Persist a new or edited session. */
    public function saveReceivingSession(ReceivingSession $session): ReceivingSession;

    /**
     * Discard a session. Called once the receipt built from it is committed, and safe to call for
     * one that is already gone: a session is work in progress, so losing the row is the intended
     * end state and never an error to report.
     */
    public function deleteReceivingSession(int $id): void;
}
