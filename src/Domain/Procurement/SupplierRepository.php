<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Domain\Procurement;

use Nandan108\InvFlux\Mutation\ActorReference;

/**
 * Repository contract for the supplier aggregate. Implemented by a storage
 * adapter (`invflux-storage-mysql` MysqlDomainStore; future Postgres, …); the
 * domain use-cases depend on this interface, not the concrete store.
 *
 * @api
 */
interface SupplierRepository
{
    /**
     * Create a supplier: mint its `supplier` actor and persist the row in one
     * transaction. The passed {@see Supplier} carries the commercial fields;
     * `actor_id` is assigned by the repository.
     *
     * `current_party_id` is assigned too — see {@see saveSupplier()} for what that promise means.
     */
    public function createSupplier(Supplier $supplier): Supplier;

    /**
     * The supplier's registered actor as an {@see ActorReference} — the scope under which the
     * supplier's own identifiers (`supplier_sku` / `supplier_barcode`) are claimed and resolved
     * in the `invflux` system.
     */
    public function actorReferenceFor(Supplier $supplier): ActorReference;

    /**
     * Persist edits to an existing supplier (does not touch the actor link).
     *
     * **Implementations must keep `current_party_id` in step with the supplier's stated facts.**
     * Those facts — legal name, address, tax number, contact — live in an interned, immutable
     * {@see DocumentParty}, so changing one does not edit a row: it mints a different one and
     * repoints. Every writer has to get that, which is why it is the repository's job and not a
     * caller's; a caller that forgets leaves the pointer aimed at what the supplier used to be,
     * silently and without failing anything.
     *
     * The old row is not removed here. It survives for as long as any document still states it,
     * and is otherwise an orphan for a reaper to collect.
     */
    public function saveSupplier(Supplier $supplier): Supplier;

    /**
     * Persist several suppliers in one round trip.
     *
     * The plural form exists because the singular one in a loop is a round trip per row, and the
     * callers that need this — backfills, importers — are exactly the ones with many rows. Saving a
     * hundred suppliers should cost one statement, not a hundred.
     *
     * Carries the same `current_party_id` promise as {@see saveSupplier()}.
     *
     * @param list<Supplier> $suppliers
     */
    public function saveSuppliers(array $suppliers): void;

    public function findSupplier(int $id): ?Supplier;

    /**
     * Find a supplier by its internal {@see Supplier::$code} (case-insensitive per the column
     * collation), or null. Used to enforce code uniqueness with a friendly message before save.
     */
    public function findSupplierByCode(string $code): ?Supplier;

    /** @return list<Supplier> */
    public function listSuppliers(): array;

    /** Upsert the (supplier, subject) commercial link. */
    public function saveSupplierProduct(SupplierProduct $link): SupplierProduct;

    /**
     * Hard-delete a supplier-product link. Historical PO lines snapshot their own unit cost,
     * so removing a catalogue link never affects past orders.
     */
    public function deleteSupplierProduct(SupplierProduct $link): void;

    /**
     * All supplier-product links for one supplier.
     *
     * @return list<SupplierProduct>
     */
    public function supplierProductsForSupplier(int $supplierId): array;

    /**
     * All supplier-product links for one subject (multi-supplier sourcing).
     *
     * @return list<SupplierProduct>
     */
    public function supplierProductsForSubject(int $subjectId): array;

    /**
     * Remove a subject from every supplier catalogue — the catalogue half of the clean-delete
     * cleanup (§5.6). Catalogue membership is not inventory (it commits nothing), so a subject can
     * be listed by suppliers without raising a deletion blocker; when the subject is legitimately
     * deleted, those links are dropped. Historical PO lines snapshot their own unit cost, so this
     * never affects past orders. Returns the number of links removed. One set-based `DELETE`.
     */
    public function removeSubjectFromCatalogues(int $subjectId): int;

    /** Insert or update a supplier contact. */
    public function saveSupplierContact(SupplierContact $contact): SupplierContact;

    /** Hard-delete a supplier contact. (A contact on past PO sends should be set inactive, not deleted.) */
    public function deleteSupplierContact(SupplierContact $contact): void;

    public function findSupplierContact(int $id): ?SupplierContact;

    /**
     * All contacts for one supplier, active first then by name.
     *
     * @return list<SupplierContact>
     */
    public function contactsForSupplier(int $supplierId): array;
}
