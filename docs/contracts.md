# Contracts

## Aggregate Contract

[`InventoryStore`](../src/Contracts/Inventory/InventoryStore.php) is the aggregate persistence-facing contract.

It combines:

- [`SchemaManager`](../src/Contracts/Inventory/SchemaManager.php)
- [`ConfigManager`](../src/Contracts/Inventory/ConfigManager.php)
- [`InventoryReader`](../src/Contracts/Inventory/InventoryReader.php)
- [`InventoryWriter`](../src/Contracts/Inventory/InventoryWriter.php)
- [`TypeRegistry`](../src/Contracts/Inventory/TypeRegistry.php)
- [`MetaLedger`](../src/Contracts/Inventory/MetaLedger.php)
- [`TransactionalStore`](../src/Contracts/Inventory/TransactionalStore.php)
- [`IdempotencyStore`](../src/Contracts/Inventory/IdempotencyStore.php)

An adapter implementation is expected to provide:
- schema bootstrap and inspection
- configuration reads and migrations
- inventory and ledger reads
- single and batch persistence
- movement/actor type registration
- meta-event recording
- transactional multi-step execution
- idempotent duplicate suppression for replay-prone application flows

## TransactionalStore

[`TransactionalStore`](../src/Contracts/Inventory/TransactionalStore.php) executes multi-step persistence work inside one atomic adapter transaction.

Use it when:

- several persistence changes must succeed or fail together
- nested store operations should share one outer commit boundary

Do not use it as a duplicate-suppression mechanism. A transaction keeps one attempt atomic. It does not answer what should happen when the same logical operation is invoked twice.

## IdempotencyStore

[`IdempotencyStore`](../src/Contracts/Inventory/IdempotencyStore.php) protects one logical operation behind one persisted key.

Use it when:

- the same business transition may be invoked more than once
- reapplying the InvFlux mutation would be wrong or risky
- later duplicate invocations should replay one stored terminal outcome

Do not use it for broad host-platform side effects inside the closure. Keep the closure focused on authoritative store work and project the terminal outcome afterward.

See [Idempotency](idempotency.md) for the full usage model.

## SchemaManager

[`SchemaManager`](../src/Contracts/Inventory/SchemaManager.php) owns declarative slot-space lifecycle:

- `bootstrap(...)`
- `schema()`
- `addDimensionValues(...)`
- `drainAndDisableDimensionValue(...)`

Important semantics:

- bootstrap records one compatible declarative schema
- added values may be activated later by add-ons
- disabling a value is a schema-change operation, not just a quantity move
- quantity drain and value disable happen together as one logical operation

## InventoryWriter

[`InventoryWriter`](../src/Contracts/Inventory/InventoryWriter.php) is the core write-side boundary.

It persists:

- single-subject movements through [`PersistMovement`](../src/Mutation/PersistMovement.php)
- batch movements through [`PersistBatchMovement`](../src/Mutation/PersistBatchMovement.php)

Writers are expected to:
- apply writes atomically
- preserve auditability
- report ordinary conflicts as result data

## InventoryReader

[`InventoryReader`](../src/Contracts/Inventory/InventoryReader.php) returns read-side DTOs:

- [`InventoryBalance`](../src/ReadModels/InventoryBalance.php)
- [`LedgerRecord`](../src/ReadModels/LedgerRecord.php)

Both balances and ledger reads support slot filtering.

## ConfigManager

[`ConfigManager`](../src/Contracts/Inventory/ConfigManager.php) covers quantity scale management:

- `quantityScale()`
- `setQuantityScale(...)`
- `migrateQuantityScale(...)`

Core defines the contract only. Storage adapters define how migration is performed.

## TypeRegistry

[`TypeRegistry`](../src/Contracts/Inventory/TypeRegistry.php) registers:

- movement types
- actor types

These give persisted inventory and meta-ledger rows stable type identities.

## MetaLedger

[`MetaLedger`](../src/Contracts/Inventory/MetaLedger.php) records configuration and schema audit events through [`MetaEvent`](../src/Mutation/MetaEvent.php).

This is intentionally separate from the inventory ledger.
