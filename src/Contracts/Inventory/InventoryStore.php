<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Contracts\Inventory;

/**
 * Aggregate contract implemented by complete InvFlux storage adapters.
 *
 * @api
 */
interface InventoryStore extends SchemaManager, ConfigManager, InventoryReader, InventoryWriter, TypeRegistry, MetaLedger, TransactionalStore, IdempotencyStore, SubjectRegistrar, SystemRegistrar, IdentifierTypeRegistrar
{
}
