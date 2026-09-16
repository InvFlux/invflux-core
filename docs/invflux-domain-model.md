# InvFlux Domain Model

> For exhaustive class shapes, table schemas, and storage APIs, see the companion
> [domain-reference.md](domain-reference.md). This document is the narrative intro;
> the reference doc is the AI-ingestion-oriented full surface.

InvFlux is the inventory domain layer built around SlotFlow.

SlotFlow provides the pure movement engine: finite slot spaces, slots, flows, quantity
states, and movement results. InvFlux adds the domain model around that engine: schema
declarations, layers, subjects, persistence commands, ledgers, registries, idempotency, and
projection hooks.

This document describes the core model. Storage-specific details are called out only as
implementation notes.

## SlotFlow Foundation

### Slot Space

A slot space is a finite set of inventory slots generated from discrete dimensions.

In SlotFlow, a slot space is the executable structure used by flows. In InvFlux, a
`SlotSpaceDefinition` is the declarative schema that can be validated, serialized,
snapshotted, and compiled into a SlotFlow `SlotSpace`.

### Slot

A slot is one concrete coordinate inside a slot space.

For example, with dimensions:

```text
stt = atp | res | ctd
loc = wh1 | wh2
```

slots include:

```text
atp.wh1
res.wh1
ctd.wh2
```

Slot keys are machine identifiers. They are not display labels.

### Flow

A flow is a movement rule over slots. InvFlux represents flows with `FlowDefinition`, a
serializable wrapper around SlotFlow flows.

Flow steps can:

- move quantity from one slot pattern to another
- create quantity into a slot pattern
- destroy quantity from a slot pattern
- apply ordering, allocation, and quantity constraint policies

SlotFlow computes movement results. InvFlux persists those results.

## Schema Model

### SlotSpaceDefinition

`SlotSpaceDefinition` describes one named slot space.

It contains:

- ordered dimensions
- registered flows
- slot rules
- metadata

Dimension order is controlled by `DimensionDefinition::$position`. This is slot-axis order,
not business priority and not presentation order.

### Dimension

A dimension is one axis of the slot space, such as state, location, owner, batch status, or
channel.

Each dimension has:

- a machine name
- an axis position
- zero or more dimension values
- a default value when statically defined
- optional lifecycle/collapse behavior
- an active flag

Most dimensions define their values directly. Shared dimensions can instead load their
values from storage and select a layer-specific view of those values.

### Dimension Value

A dimension value is one possible value inside a dimension.

The term "dimension value" names the domain entity. It does not mean that the entity's
machine identity field is called `value`.

A dimension value has:

- `code`: stable machine identifier used in slot keys, filters, patterns, and ledger-facing
  references
- `name`: optional human-targeted display label
- `ownerKey`: owner of the value definition
- `active`: whether new slot compilation should include it
- `removalTargetCode`: optional target used when draining/removing the value
- `metadata`: extension data
- `parentCode`: optional parent value code for hierarchies
- `addressable`: whether it is an operation target for leaf-addressed views
- `level`: optional structural level name, such as `warehouse`, `zone`, or `bin`

Codes should be compact, stable, and machine-friendly. A root's is a bare segment (`wh1`); a
child's is its parent's plus one (`wh1/zone-a`, then `wh1/zone-a/bin-01`). Names carry the
human-friendly form, for example `Lausanne Warehouse`.

Dimension values do not have declaration positions. When a deterministic internal order is
needed, implementations should sort by code unless a UI or policy explicitly chooses a
different presentation or business order.

### Hierarchical Dimension Values

A dimension can form a hierarchy through `parentCode`.

For example:

```text
ch
ch/vd
ch/vd/wh1
ch/vd/wh1/zone-a
ch/vd/wh1/zone-a/bin-01
```

A child's code **is** its parent's code plus one segment. The two carry different halves of the
same fact: `parentCode` makes the edge a real relationship, and the code makes reading ancestry a
string operation rather than a walk. Storage enforces both, so a code that skips a level or
detaches from its parent is refused rather than stored.

This is why segments stay short — they accumulate into every descendant's code. Merchant-readable
text belongs in `name`, which is the field that has no length pressure on it.

`level` names the structural role of a value. A deep location hierarchy might use:

```text
country > region > warehouse > zone > aisle > bin
```

The physical leaf for a warehouse is not always a bin. A value is treated as a leaf
operation target through addressability and layer selection, not by assuming a fixed depth.

### Shared Dimension

A shared dimension is one logical dimension used by multiple layers.

The values belong to the shared dimension once, but each layer can address a different view
of those values. This is how one `loc` dimension can be used commercially at warehouse level
and physically at leaf level.

Shared dimensions are declared with `DimensionDefinition::sharedRef()` and an explicit
`DimensionValueSelector`.

### DimensionValueSelector

`DimensionValueSelector` declares which subset of a shared dimension a layer addresses.

Supported selectors:

- `root()`: values with no parent
- `level('warehouse')`: values tagged with a named structural level
- `levels('warehouse', 'external')`: values tagged with **any** of several level names, for a
  layer addressing more than one kind of place at the same grain
- `leaf()`: addressable leaf values

Level matching is depth-independent, so a level name stays accurate however many grouping tiers
are nested above it.

Selectors are part of the schema contract. Missing selectors should be treated as invalid
schema state, not inferred.

### DimensionScope

`DimensionScope` restricts a boundary operation to one value of a shared dimension.

For exact layer views, the scoped value matches directly. For layer views below the scoped
value in a hierarchy, the scope should include descendant values. For example, a boundary
operation scoped to `loc = wh1` should affect commercial slots at `wh1` and physical leaf
slots below `wh1`.

## Layer Model

### Layer

A layer is a named inventory view with its own slot space.

Examples:

- commercial layer: sellable state by warehouse
- physical layer: physical stock by concrete location

Layers let the same stock be represented in different operational vocabularies while still
being kept consistent by boundary operations.

### LayeredSlotSpaceDefinition

`LayeredSlotSpaceDefinition` is a map of layer name to `SlotSpaceDefinition`.

It is the schema for a multi-layer inventory model. Each layer compiles into its own
SlotFlow `SlotSpace`.

### BoundaryFlow

A boundary flow is one business operation that runs across multiple layers.

It maps each layer to the flow that should run in that layer. The requested quantity is the
same business quantity for all layers, while each layer may move or destroy that quantity
using different slot patterns.

### LayeredMovementEngine

`LayeredMovementEngine` executes each layer independently with SlotFlow, then returns a
combined result.

SlotFlow execution is pure: it produces deltas and does not mutate storage. That lets
InvFlux inspect all layer results before deciding whether to persist anything.

## Inventory Model

### Subject

A subject is the thing that owns inventory.

Subjects can represent units, batches, or aggregate product nodes depending on the adapter
and business model. Core identifies them with `SubjectId`.

### Quantity State

Inventory state is quantity held by a subject at a slot.

Core treats persistence as an adapter concern. The essential invariant is:

```text
subject + slot -> quantity
```

### Movement Persistence

InvFlux separates movement calculation from movement persistence.

The normal flow is:

1. Compile a schema into SlotFlow slot spaces.
2. Load inventory state for one or more subjects.
3. Execute SlotFlow movement logic.
4. Convert the result into deltas.
5. Persist those deltas atomically through an `InventoryStore`.

Single-subject writes use `PersistMovement`. Multi-subject writes use `PersistBatchMovement`.
Storage-backed execution helpers can load state, execute SlotFlow, and persist in one call.

### Quantity Guards

`QuantityGuard` expresses commit-time min/max checks for a subject-slot update.

Guards are persistence checks. They protect against stale reads and concurrent writes after
SlotFlow has already computed a movement result.

### Layer Totals

Layer totals are a read model of total quantity per subject and layer.

They are useful for diagnostics and consistency checks across layers. They are not a
replacement for slot-level authoritative state.

## Ledger, Registry, and Audit Model

### Movement Type

Movement types classify persisted inventory movements.

They are registered by owner key and code, allowing core, adapters, and add-ons to define
their own movement vocabularies without code collisions.

### Actor

Actors identify who or what caused an operation.

An actor has a type, such as `admin`, `system`, or `plugin`, and an optional actor
reference.

### Entity Reference

Entity references attach a business object to a movement or config event.

Examples include orders, purchase orders, shipments, returns, transfers, stock takes, or
dimension value lifecycle events.

### Inventory Ledger

The inventory ledger records persisted movement facts.

Conceptually, a ledger row says:

```text
subject moved quantity from slot A to slot B at time T because of movement type M
```

Creation has no source slot. Destruction has no destination slot.

### Config Ledger

The config ledger records schema and configuration events, including schema snapshots.

Schema snapshots make it possible to inspect the model as it existed at a point in time.

### Idempotency

Idempotency protects external business operations from being applied more than once.

An `IdempotencyKey` claims a scope and operation key. A completed execution stores a
terminal or retryable outcome. Replays return the recorded outcome instead of repeating the
operation.

## Projection Model

Authoritative state belongs to InvFlux inventory storage. Projections are derived read
models or external platform updates.

`ProjectionParticipant` is the extension point for transactional projections.

A participant can:

- declare lock targets
- acquire those locks
- apply its projection update inside the same transaction as the inventory write

Projection participants should not be treated as the source of truth.

## Implementation Notes

The following notes describe the current MySQL adapter. They are implementation examples,
not requirements for every adapter.

- Dimension values are stored with a numeric primary key for joins and foreign keys, while
  `code` is the natural key within a dimension.
- Hierarchy is stored through `parent_id` plus the value's own `code`, which **is** its full
  path — a child's code sits under its parent's, `oh/main` under `oh`. Ancestry is therefore a
  prefix test on `code`, and no materialised path column sits beside it.
- Slot rows are stored in `invflux_slotspace`, with one dynamic `dim_*` column per active
  dimension.
- Inventory state is stored as `(subject_id, slot_id) -> quantity`.
- Boundary-flow descendant matching compares dimension value codes by prefix, separator
  included, so a code cannot claim a sibling that merely starts the same way.
- Parent/ancestor physical slots can be maintained as aggregate read-model rows even when
  they are not active flow targets.
- Deepening the tree keeps stock with the value that already held it. Hierarchisation demotes
  that value into a child position (`wh1` becomes `wh1/unassigned`) and mints a new parent above
  it to take the vacated code. Slot ids are unchanged, so `inventory_state` and the ledger are
  not rewritten and the history re-reads under the longer name; a `location_hierarchised` row
  records the relabelling.

## Design Boundaries

- SlotFlow computes movement possibilities and deltas; InvFlux owns schema, persistence,
  audit, and projections.
- Dimension value code is identity. Dimension value name is display.
- Dimension value order is not persisted domain state.
- Layer selection is explicit through `DimensionValueSelector`.
- Business priority should be modeled as flow policy, not as dimension or dimension-value
  declaration order.
- Projections and layer totals are derived state; authoritative inventory remains
  subject-slot quantity.
