<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Contracts\Inventory;

use Nandan108\InvFlux\Registry\ActorTypeDefinition;
use Nandan108\InvFlux\Registry\MovementTypeDefinition;
use Nandan108\InvFlux\Registry\RefTypeDefinition;
use Nandan108\InvFlux\Registry\SurfaceTypeDefinition;

/**
 * Register controlled vocabularies used by ledgers and audit records.
 *
 * Implementations idempotently INSERT IGNORE the registered definitions on each
 * call, then return the assigned numeric ids on lookup. Callers (subsystems
 * contributing their own vocabulary) call the relevant `register*` method at
 * install time; downstream code resolves the numeric id via the matching
 * `resolve*Id` method when writing rows that reference the registry.
 *
 * @api
 */
interface TypeRegistry
{
    /**
     * Register or refresh the movement types owned by one subsystem.
     *
     * @param non-empty-string             $ownerKey
     * @param list<MovementTypeDefinition> $movementTypes
     */
    public function registerMovementTypes(string $ownerKey, array $movementTypes): void;

    /**
     * Register or refresh the actor types allowed in ledgers.
     *
     * @param list<ActorTypeDefinition> $actorTypes
     */
    public function registerActorTypes(array $actorTypes): void;

    /**
     * Register or refresh ref types — the kinds of business entities a ledger or
     * event row may point at (`order`, `purchase_order`, `correction`,
     * `order_line`, etc.).
     *
     * The `$ownerKey` identifies the subsystem contributing the types (e.g.,
     * `'order'` for the order domain, `'wms'` for a future Scale add-on). It's
     * informational — the underlying table has a UNIQUE constraint on `code`, so
     * two subsystems trying to register the same code will land on the same row.
     *
     * @param non-empty-string        $ownerKey
     * @param list<RefTypeDefinition> $refTypes
     */
    public function registerRefTypes(string $ownerKey, array $refTypes): void;

    /**
     * Register or refresh surface types — the categories of entry points
     * (`admin_page`, `api`, `cli`, `system`, `webhook`, …).
     *
     * @param non-empty-string            $ownerKey
     * @param list<SurfaceTypeDefinition> $surfaceTypes
     */
    public function registerSurfaceTypes(string $ownerKey, array $surfaceTypes): void;

    /**
     * Resolve a registered ref-type code to its numeric id, or null if not registered.
     *
     * Used by audit/event writers when the row must reference a typed entity
     * (`ref_type_id` + `ref_id`).
     */
    public function resolveRefTypeId(string $code): ?int;

    /**
     * Resolve a registered surface-type code to its numeric id, or null if not registered.
     */
    public function resolveSurfaceTypeId(string $code): ?int;
}
