# InvFlux Domain Reference

Comprehensive reference for the InvFlux core domain layer, the SlotFlow primitives it
builds on, and the shapes of the MySQL adapter that persists it. Aimed at AI ingestion —
optimized for completeness and precise shapes rather than narrative.

Companion doc: [invflux-domain-model.md](invflux-domain-model.md) — narrative-style
introduction to the same model. Read that first if you want prose; read this for exact
shapes.

## Reading guide

- **Compute / Persist boundary.** SlotFlow is pure compute (no I/O). InvFlux owns
  schema, mutation requests, persistence contracts, audit, and projections. Storage is
  one adapter — currently MySQL.
- **All public properties are `readonly` unless noted otherwise.** Constructor params
  with `?` are nullable; param order matches the listed constructor.
- **FQCN namespaces.** Core lives under `Nandan108\InvFlux\…`. SlotFlow lives under
  `Nandan108\SlotFlow\…`. Storage adapter lives under
  `Nandan108\InvFlux\MysqlStorage\…`.
- **Lock order.** Within compound transactions: (1) attrecord domain locks
  (`LockSet::acquire`), (2) inventory state locks (`MysqlInventoryStore` internal
  temp-table + `SELECT FOR UPDATE`). Never reverse.

---

## 1. Package map

| Package | Role | Depends on |
|---|---|---|
| `slot-flow` | Pure movement engine: slot spaces, flows, allocators, constraint policies. No I/O. | — |
| `invflux-core` | Domain model: schema, layers, mutation requests, contracts, registry, ledger reads, idempotency, projections. Order aggregate. | `slot-flow`, `attrecord` |
| `invflux-storage-mysql` | Two cooperating stores sharing one `MysqlSession`: `MysqlInventoryStore` (raw SQL) and `MysqlDomainStore` (attrecord). | `invflux-core` |
| `attrecord` | Lightweight active-record (dialect-abstracted). Used by domain Records and storage. | — |

Within core, code is grouped by directory:

```
Schema/    Layer/    Flow/    Mutation/    Domain/Order/    Domain/Subject/
Contracts/ Policy/   Projection/    Registry/  Results/    ReadModels/
Idempotency/  Diagnostics/   Exceptions/    Util/
```

---

## 2. SlotFlow Foundation

SlotFlow is a pure-PHP movement engine. Given a `SlotSpace`, a `QuantityState`, and a
`Flow`, the `MovementEngine` returns a `MovementResult` (events + deltas + remainder)
without touching storage. InvFlux turns those deltas into persisted ledger rows and
state updates.

### Concepts

- **SlotSpace** — finite universe of valid slots generated from named dimensions
  (e.g. `loc × stt`). Holds slot rules, edge rules, and registered flows.
- **Slot** — one concrete tuple of dimension values (e.g. `loc=wh1, stt=atp`) or the
  special **nil slot** (boundary into/out of space).
- **Flow** — named ordered sequence of movement steps. Each step has `from`/`to` slot
  patterns and optional policies.
- **MovementEdge** — directed edge between two slots, computed on demand from edge
  rules. Carries label + attributes (including policies attached at runtime).
- **MovementResult** — immutable summary: list of movement events, remainder
  quantity, and `deltas()` (net per-slot change, first-seen order).
- **QuantityState** — in-memory per-subject quantity map keyed by slot. Mutated by
  the application *after* engine returns deltas — never by the engine.
- **Policies** — pluggable per-step decision-makers:
  - `EdgeOrderingPolicyInterface::orderEdges(FlowContext): list<MovementEdge>` —
    reorder candidate edges.
  - `QttyConstraintPolicyInterface::constraint(MovementEdge, FlowContext): int|float` —
    cap movable quantity per edge.
  - `AllocationPolicyInterface::allocate(FlowContext): list<AllocationDecision>` —
    pick explicit edges + quantities.
  - `SerializablePolicy` — opt-in marker: implements
    `toDefinition(): array` and `static fromDefinition(array): static`.

### Types consumed by invflux-core

#### `Nandan108\SlotFlow\SlotSpace` (final class)

```php
__construct(
    array $dimensions,
    ?TimeAxis $timeAxis = null,
    TimedDurationResolverInterface|Closure|null $durationResolver = null,
    ?string $codecClass = null,
)

public SlotCodec $codec;
public readonly ?TimeAxis $timeAxis;
public array $flows;       // keyed by flow name
public array $cascades;    // deprecated alias for $flows
```

Key methods:

- `static define(array $dimensions, ?string $codecClass = null): self`
- `static defineTimed(array $dimensions, TimeAxis $timeAxis, …): self`
- `slotRules(RuleSet|array $rules): self` / `edgeRules(RuleSet|array $rules): self`
- `flow(string $name, Closure|array $builder): self`
- `getFlow(string $name): Flow`
- `slot(Slot|array|string|null $keyOrValues): Slot` (throws on miss)
- `trySlot(...): ?Slot`
- `matchPattern(array|string|null $pattern): list<Slot>`
- `matchPartial(?array $partial): list<Slot>`
- `expandSlotPattern(string|array|null $pattern): list<array>`
- `dimensionNames(): list<string>` / `dimensions(): array<string, list<string>>`
- `dimensionValues(string $dimension): list<string>`
- `nilSlot(): Slot`
- `subjectKeyResolver(callable $resolver): self` / `subjectKey(mixed $subject): string`
- `getEdgesFrom(Slot $from): list<MovementEdge>`

#### `Nandan108\SlotFlow\Slot` (final class, ArrayAccess)

```php
__construct(
    public readonly string $key,
    public readonly ?array $dimensions,   // null for nil slot
    public readonly SlotSpace $space,
    public readonly array $attributes = [],
)
```

- `isNil(): bool`
- `dimension(string $name): ?string`
- `equals(Slot $other): bool`
- `withMeta(array $attributes): self`
- `with(?array $overrides): list<Slot>`
- `matches(array|string|null $pattern): bool`
- `outgoingEdges(): list<MovementEdge>`

#### `Nandan108\SlotFlow\MovementEdge` (final class)

```php
__construct(
    public readonly Slot $from,
    public readonly Slot $to,
    public readonly ?string $label = null,
    public readonly array $attributes = [],
)
```

- `meta(array $attributes): self`
- `policies(): array` / `plannerRules(): array` / `shipmentCalendarRules(): array`

#### `Nandan108\SlotFlow\Flow` (class; `Cascade` is deprecated alias)

```php
__construct(string $name)
```

- `static define(string $name, Closure $builder): static`
- `static fromFlow(self $flow): static`
- `name(): string` / `steps(): list<FlowStep>`
- `move(string|array|null $from, string|array|null $to): FlowStepBuilder`
- `create(string|array|null $to): FlowStepBuilder`
- `destroy(string|array|null $from): FlowStepBuilder`
- `stepByLabeledEdges(string ...$edgeLabels): FlowStepBuilder`
- `reverseIf(bool $condition, bool $flipEdges = true): static`

#### `Nandan108\SlotFlow\QuantityState` (class)

```php
__construct(private SlotSpace $space, array $tuples = [])
```

- `get(Slot|array|string|null $slot): int|float`
- `getSum(Slot|array|string|null ...$slotPatterns): int|float`
- `setTuple(array $slots): void`
- `setSlotQtty(Slot $slot, int|float $quantity): void`
- `add(Slot $slot, int|float $delta): void`
- `all(): array<string, int|float>` (keyed by slot key)
- `slotAttributes(Slot $slot): array` / `allSlotAttributes(): array`
- `slotAttribute(Slot $slot, string $name, mixed $default = null): mixed`
- `copy(): static` / `space(): SlotSpace`
- `addFromRows(array $rows, Closure $resolver): static`
- `static fromRows(SlotSpace $space, array $rows, Closure $resolver): static`

#### `Nandan108\SlotFlow\MovementResult` (final class)

```php
__construct(
    public readonly array $events,     // list<MovementEvent>
    public readonly int|float $remaining,
)
```

- `isComplete(): bool` — `$remaining === 0`
- `deltas(): list<QuantityStateDelta>` — stable first-seen-slot order
- `ledgerEntries(array $context = []): list<array>`

#### `Nandan108\SlotFlow\Results\QuantityStateDelta`

```php
__construct(public readonly Slot $slot, public readonly int|float $delta)
```

#### `Nandan108\SlotFlow\MovementEngine` (final class)

```php
__construct(private readonly ExecutionSolverInterface $solver = new GreedyFlowSolver())
```

- `execute(QuantityState $inventory, SlotSpace $space, string|Flow $cascade, int|float $quantity, mixed $subject = null, array $appContext = [], array $params = []): MovementResult`

#### `Nandan108\SlotFlow\Batch\QuantityStateBatch<TSubject>` (final class)

```php
__construct(private array $items)   // array<BatchItem<TSubject>>
```

- `static fromRows(SlotSpace $space, iterable $rows, Closure $subjectGetter, Closure $slotRowGetter, callable $quantityGetter): self`
- `items(): list<BatchItem>` / `results(): list<MovementResult>`
- `deltas(): list<BatchQuantityStateDelta>` — flattened across subjects
- `ledgerEntries(array $context = []): list<array>`

#### `Nandan108\SlotFlow\Runtime\FlowContext`

```php
__construct(
    public readonly SlotSpace $space,
    public readonly array $edges,     // list<MovementEdge>
    public readonly QuantityState $inventory,
    public readonly int|float $quantity,
    public readonly mixed $subject = null,
    public readonly array $context = [],
)
```

#### `Nandan108\SlotFlow\Rules\SlotRule` (final class)

```php
__construct(
    public readonly bool $allow,
    public readonly string|array|null $pattern,
    public readonly array $attributes = [],
)
```

- `static allow($pattern, array $meta = []): self`
- `static deny($pattern, string|array|null ...$patterns): self|RuleSet`
- `static denyAll(array $patterns): list<self>`
- `meta(array $attributes): self`

#### `Nandan108\SlotFlow\Policies\DimensionPriority` (final, EdgeOrderingPolicyInterface + SerializablePolicy)

```php
__construct(private readonly array $priorities)   // array<dimension, list<value>>
```

Bundled ordering policy: rank edges by configured priority tiers on source-slot
dimensions.

#### `Nandan108\SlotFlow\Exceptions\SlotFlowInvalidArgumentException`

```php
__construct(string $message = '', private readonly array $debugContext = [], int $code = 0, ?Throwable $previous = null)
```

- `debugContext(): array`

---

## 3. Schema (`Nandan108\InvFlux\Schema`)

Declarative, JSON-serializable schema for slot spaces. Compiled to SlotFlow at runtime.

### `SlotSpaceDefinition` (final class)

```php
__construct(
    public readonly string $name,
    public readonly array $dimensions,   // list<DimensionDefinition>
    public readonly array $metadata = [],
    public readonly array $flows = [],   // keyed by flow name
    public readonly array $rules = [],   // list<SlotRule>
)
```

- `static define(string $name, array $dimensions, array $metadata = []): self`
- `dimensionsAsArray(): array<string, list<string>>` — active dimensions normalized
- `activeDimensions(): list<DimensionDefinition>` — sorted by `$position`
- `dimensionByName(string $name): ?DimensionDefinition`
- `withFlow(FlowDefinition $flow): self`
- `withRule(SlotRule ...$rules): self`
- `toDefinition(): array<string, mixed>` — JSON-serializable snapshot
- `toSlotSpace(): SlotSpace` — compile

### `DimensionDefinition` (final class)

```php
__construct(
    public readonly string $name,
    public readonly int $position,
    public readonly array $values,             // list<DimensionValueDefinition>
    public readonly string $defaultValue,
    public readonly DimensionKind $kind = DimensionKind::Partition,
    public readonly CollapseBehavior $collapseBehavior = CollapseBehavior::Aggregate,
    public readonly ?string $collapseTargetValue = null,
    public readonly bool $active = true,
    public readonly array $metadata = [],
    public readonly bool $isSharedRef = false,
    public readonly ?DimensionValueSelector $valueSelector = null,
)

public readonly bool $hasHierarchy;   // computed from values' parentCode
```

- `static define(string $name, array $values, int $position = 0, ?string $defaultValue = null, DimensionKind $kind = …, CollapseBehavior $cb = …, ?string $collapseTargetValue = null, bool $active = true, array $metadata = []): self`
- `static sharedRef(string $name, int $position, DimensionValueSelector $valueSelector): self`
- `valuesAsList(): list<string>` — active values, ordered
- `allValuesAsList(): list<string>` — includes inactive
- `valuesByCode(?string $code = null, bool $includeInactive = false): array|DimensionValueDefinition|null`
- `withResolvedValues(array $values, string $defaultValue): self` — used by sharedRef bootstrap

### `DimensionValueDefinition` (final class)

```php
__construct(
    public readonly string $code,
    public readonly ?string $name = null,
    public readonly string $ownerKey = 'core',
    public readonly bool $active = true,
    public readonly ?string $removalTargetCode = null,
    public readonly array $metadata = [],
    public readonly ?string $parentCode = null,
    public readonly bool $addressable = true,
    public readonly ?string $level = null,
)
```

- `code` is identity (machine-friendly: `wh1`, `bin-a-01`).
- `name` is display (`Lausanne Warehouse`).
- `parentCode` forms hierarchies; `level` names the structural role
  (`warehouse`, `zone`, `bin`).
- `addressable=false` excludes the value from operation targets in leaf-addressed views.

### `DimensionKind` (string enum)

| Case | Value | Meaning |
|---|---|---|
| `Partition` | `partition` | Only subdivides already-visible stock |
| `Reveal` | `reveal` | Exposes previously hidden semantics |

### `CollapseBehavior` (string enum)

| Case | Value | Behavior when dimension collapsed |
|---|---|---|
| `Aggregate` | `aggregate` | Sum across collapsed dimension |
| `DropHidden` | `drop_hidden` | Discard hidden inventory |
| `ForbidIfNonEmpty` | `forbid_if_nonempty` | Refuse collapse while stock exists |
| `ArchiveThenDrop` | `archive_then_drop` | Archive, then drop |
| `MapToValue` | `map_to_value` | Remap to `collapseTargetValue` |

### `DimensionValueSelector` (final class)

```php
__construct  // private; use factories
public readonly DimensionValueSelectorKind $kind;
public readonly ?string $level;
```

- `static root(): self` — values with `parentCode === null`
- `static level(string $level): self` — values tagged with given level
- `static leaf(): self` — addressable leaves
- `static fromArray(array $data): self`
- `toArray(): array<string, string>`

### `DimensionValueSelectorKind` (string enum)

`Root = 'root'`, `Level = 'level'`, `Leaf = 'leaf'`.

### `DimensionScope` (final class)

```php
__construct(public readonly string $dimension, public readonly string $value)
```

Restricts a boundary operation to one value of a shared dimension. For layer views
below this value in a hierarchy, descendant values are included.

### `SlotSpaceFactory` (final class)

Adapter-shared factory for the default layered slot space.

```php
const LAYER_COMMERCIAL = 'commercial';
const LAYER_PHYSICAL   = 'physical';
const DEFAULT_LOCATION_SEED = 'oh';

// Execute-time parameters the registered flows name in their patterns
const PARAM_LOCATION = 'location';
const PARAM_SLOT     = 'slot';
const PARAM_DEFICIT  = 'deficit';

// Parameterized flows, by name
const FLOW_WRITE_IN              = 'write_in';
const FLOW_WRITE_OFF             = 'write_off';
const FLOW_STOCK_ADJUST_ATP_ADD  = 'stock_adjust_atp_add';
const FLOW_STOCK_ADJUST_ATP_SUB  = 'stock_adjust_atp_sub';
const FLOW_RECONCILE_ADD         = 'reconcile_add';
const FLOW_RECONCILE_SUB         = 'reconcile_sub';
```

- `createLayered(): LayeredSlotSpaceDefinition`

There is deliberately no method here that compiles a ready-to-run `SlotSpace`. A shared dimension
(`stt`, `loc`) declares no values in the definition — they live in the database, because add-ons and
merchants add them — so anything compiled from this class alone knows only what code knows. Ask the
store for the live layer instead: `SchemaManager::layerSchema($layerName)->toSlotSpace()`.
- `reconciliationFlowName(string $slotKey, int $delta): string`
- `stockAdjustAtpFlowName(int $delta): string`

**Flows are named, not built.** A pattern value of `{location}` (or `{slot}`) is substituted from
the execute params when the flow runs, so one registered definition serves every location — and
anything that can name a flow can reach it. Execute one as:

```php
$engine->execute($inventory, $space, SlotSpaceFactory::FLOW_WRITE_IN, $qty, params: [
    SlotSpaceFactory::PARAM_LOCATION => $location,
    SlotSpaceFactory::PARAM_DEFICIT  => $deficit,   // optional; absent ⇒ 0
]);
```

A parameter the pattern names and the caller omits is **refused** where it is substituted — it never
degrades into a flow that matches nothing. `PARAM_DEFICIT` is the exception, and deliberately so: a
missing cap has a correct default (0), a missing target does not.

The two `*FlowName()` methods return a name rather than a flow because all they decide is *which* of
a pair, plus the refusals that are not a pattern's business — reconciliation is native-slot-only (a
regime boundary), and neither accepts a zero delta.

---

## 4. Layer (`Nandan108\InvFlux\Layer`)

A layer is a named slot space. The same business stock can be modeled in multiple
operational vocabularies (commercial = `stt × loc(warehouse)`, physical = `stt × loc(bin)`).
Boundary flows execute against each layer with its own flow body but a shared
business quantity.

### `LayeredSlotSpaceDefinition` (final class)

```php
__construct(public readonly array $layers)   // array<string, SlotSpaceDefinition>
```

- `static define(array $layers): self`
- `toSlotSpaces(): array<string, SlotSpace>`
- `withBoundaryFlow(array $layerFlows): self`
- `layerNames(): list<string>`
- `toDefinition(): array<string, mixed>`

### `LayeredQuantityState` (final class)

```php
__construct(public readonly array $states)   // array<string, QuantityState>
```

- `static define(array $states): self`
- `forLayer(string $layer): QuantityState`
- `all(): array<string, QuantityState>`

### `BoundaryFlow` (final class)

```php
__construct(public readonly array $flows)   // array<string layerName, string flowId>
```

- `static define(array $flows): self`
- `forLayer(string $layer): string`
- `all(): array<string, string>`
- `layerNames(): list<string>`

### `LayeredMovementEngine` (final class)

```php
__construct(MovementEngine $engine = new MovementEngine())
```

- `execute(LayeredQuantityState $state, array $spaces, BoundaryFlow $flow, int|float $quantity, mixed $subject = null): LayeredMovementResult`

Executes each layer independently; SlotFlow stays pure, so InvFlux inspects all layer
results before deciding whether to persist anything.

### `LayeredMovementResult` (final class)

```php
__construct(public readonly bool $ok, array $byLayer)   // array<string, MovementResult>
```

- `byLayer(): array<string, MovementResult>`
- `forLayer(string $layer): MovementResult`
- `maxFulfillable(int|float $requested): int|float` — minimum across layers

---

## 5. Flow (`Nandan108\InvFlux\Flow`)

Declarative, serializable wrapper around SlotFlow `Flow`. Steps may carry ordering,
constraint, and allocation policies — each as a `SerializablePolicy`,
`PolicyDescriptor` (adapter for non-serializable), or `callable`.

### `FlowDefinition` (final class)

- `static define(string $name): self`
- `name(): string` / `isSerializable(): bool`
- `move(string|array|null $from, string|array|null $to): self`
- `create(string|array|null $to): self`
- `destroy(string|array|null $from): self`
- `orderBy(EdgeOrderingPolicyInterface|PolicyDescriptor $policy): self`
- `constraint(QttyConstraintPolicyInterface|PolicyDescriptor|callable $constraint): self`
- `allocate(AllocationPolicyInterface|PolicyDescriptor|callable $policy): self`
- `compile(Flow $flow): void` — append steps onto existing SlotFlow Flow
- `toFlow(): Flow` — create new compiled Flow
- `toDefinition(): array<string, mixed>`
- `static fromDefinition(array $data): self`

### `FlowStepDefinition` (final class, internal)

```php
__construct(
    public readonly string|array|null $from,
    public readonly string|array|null $to,
)
public array $ordering;       // EdgeOrderingPolicyInterface|PolicyDescriptor
public array $constraints;    // QttyConstraint…|PolicyDescriptor|callable
public array $allocations;    // Allocation…|PolicyDescriptor|callable
```

### `PolicyDescriptor` (final class)

```php
__construct(
    public readonly object $policy,
    public readonly array $definition,
    public readonly string $type,
)
```

Adapter for policies that cannot implement `SerializablePolicy` directly.

### Built-in constraints

All three implement `QttyConstraintPolicyInterface + SerializablePolicy`.

#### `MaxFlowQuantity`

```php
static define(int|float $max): self
constraint(MovementEdge $edge, FlowContext $ctx): int|float
```

Caps total quantity moved in one flow execution.

#### `MaxSlotTotal`

```php
static define(string $pattern, int|float $max): self
constraint(MovementEdge $edge, FlowContext $ctx): int|float
```

Caps the quantity in destination slots matching `$pattern`.

#### `MinSlotTotal`

```php
static define(string $pattern, int|float $min): self
constraint(MovementEdge $edge, FlowContext $ctx): int|float
```

Keeps at least `$min` units in source slots matching `$pattern`.

---

## 6. Subjects (`Nandan108\InvFlux\Domain\Subject`)

A **subject** is the thing that owns inventory. Subjects are persisted with a stable
integer PK (`SubjectId`) and can be units, batches, or aggregate product nodes.
External systems (Woo, Shopify, ERP) point to subjects through time-windowed
**identifier assignments**.

### `SubjectId` (final class)

```php
__construct(public readonly int $id)   // must be positive
```

- `equals(SubjectId $other): bool`

### `SubjectKind` (string enum)

| Case | Value | Role |
|---|---|---|
| `Aggregate` | `aggregate` | Grouping node; no direct inventory; children are Units |
| `Unit` | `unit` | Commercial inventory unit; holds commercial-layer inventory |
| `Batch` | `batch` | Physical inventory leaf; one lot/batch of a Unit |

Predicates:
- `isAggregate(): bool`, `isUnit(): bool`, `isBatch(): bool` — readability sugar at call
  sites that branch on the role.

### `SubjectIdentifierAssignment` (final class)

Read model for one row of the assignment table.

```php
__construct(
    public readonly SubjectId $subjectId,
    public readonly string $systemSlug,
    public readonly string $typeCode,
    public readonly string $value,
    public readonly ?ActorReference $scopeActor,
    public readonly bool $isPrimary,
    public readonly \DateTimeImmutable $validFrom,
    public readonly \DateTimeImmutable $validTo,
)
```

- `isActive(?\DateTimeImmutable $asOf = null): bool`

### `IdentifierAssignmentPolicy` (final class)

Declares how one external `(system, type)` pair behaves. Four flags:

```php
__construct(
    public readonly bool $uniqueActiveValue = false,
    public readonly bool $reusableAfterExpiry = true,
    public readonly bool $lifecycleAnchor = false,
    public readonly bool $mutableAlias = true,
)
```

| Flag | Meaning |
|---|---|
| `uniqueActiveValue` | At most one active assignment for `(system, type, scope, value)` |
| `reusableAfterExpiry` | If false, retired values cannot be reclaimed for a different subject |
| `lifecycleAnchor` | This identifier drives subject lifecycle (e.g. Woo post id) |
| `mutableAlias` | The value may be changed/replaced for the same subject |

### `IdentifierValidity` (final class)

```php
const OPEN_ENDED = '9999-12-31 23:59:59.999999';
static openEnded(): \DateTimeImmutable
```

---

## 7. Mutation requests (`Nandan108\InvFlux\Mutation`)

Inputs to persistence. All immutable.

### `PersistMovement` (final class) — single-subject

```php
__construct(
    public readonly SubjectId $subjectId,
    public readonly string $movementTypeOwnerKey,
    public readonly string $movementTypeCode,
    public readonly MovementResult $movementResult,
    public readonly ?EntityReference $reference = null,
    public readonly ?ActorReference $actor = null,
    public readonly array $guardsBySlotKey = [],       // array<string, QuantityGuard>
    public readonly ?\DateTimeImmutable $recordedAt = null,
    public readonly ?SurfaceReference $surface = null,
)
```

- `guardFor(string $slotKey): ?QuantityGuard`
- `recordedAt(): \DateTimeImmutable` — memoized, defaults to `now()`

### `PersistBatchMovement` (final class) — multi-subject

```php
__construct(
    public readonly QuantityStateBatch $movementBatch,
    public readonly \Closure $subjectIdResolver,        // (mixed $subject) => SubjectId
    public readonly string $movementTypeOwnerKey,
    public readonly string $movementTypeCode,
    public readonly ?EntityReference $reference = null,
    public readonly ?ActorReference $actor = null,
    public readonly array $guardsBySubjectIdAndSlotKey = [],
    public readonly ?\DateTimeImmutable $recordedAt = null,
    public readonly ?SurfaceReference $surface = null,
)
```

- `recordedAt(): \DateTimeImmutable`
- `guardFor(SubjectId $subjectId, string $slotKey): ?QuantityGuard`
- `subjectIdFor(mixed $subject): SubjectId`

### `StorageBatchFlowRequest` (final class)

Used by storage-backed execution helpers that load state, execute SlotFlow, and
persist in one call. Multi-subject single-layer.

```php
__construct(
    public readonly string $movementTypeOwnerKey,
    public readonly string $movementTypeCode,
    public readonly string|Flow $flow,
    public readonly ?array $subjectIds = null,           // list<SubjectId>
    public readonly ?array $slotFilters = null,          // dimension filters
    public readonly array $params = [],
    public readonly ?EntityReference $reference = null,
    public readonly ?ActorReference $actor = null,
    public readonly array $executionContext = [],
    public readonly ?\DateTimeImmutable $recordedAt = null,
    public readonly ?array $quantitiesBySubjectId = null,
    public readonly ?\Closure $quantityResolver = null,
    public readonly ?string $layerName = null,
)
```

### `StorageBoundaryFlowRequest` (final class)

Same shape, but executes a `BoundaryFlow` across all layers and accepts a
`DimensionScope`.

```php
__construct(
    public readonly string $movementTypeOwnerKey,
    public readonly string $movementTypeCode,
    public readonly BoundaryFlow $flow,
    public readonly ?array $subjectIds = null,
    public readonly array $params = [],
    public readonly ?EntityReference $reference = null,
    public readonly ?ActorReference $actor = null,
    public readonly array $executionContext = [],
    public readonly ?\DateTimeImmutable $recordedAt = null,
    public readonly ?array $quantitiesBySubjectId = null,
    public readonly ?DimensionScope $dimensionScope = null,
)
```

### `ActorReference` (final class)

```php
__construct(public readonly string $typeCode, public readonly ?string $actorId = null)
```

Identifies actor responsible for the operation. `typeCode` resolves to
`invflux_actor_types.id`; `actorId` is the actor's natural reference (user id, system
name, etc.).

### `SurfaceReference` (final class)

```php
__construct(public readonly string $typeCode, public readonly string $ref)
```

Surface = entry point through which the operation entered InvFlux (admin page, REST
endpoint, CLI command, webhook). `typeCode` resolves to
`invflux_surface_types.id`; `ref` is the concrete instance (URL, command name).

### `EntityReference` (final class)

```php
__construct(public readonly string $type, public readonly int|string $id)
```

Business entity attached to a movement or audit event (order, PO, shipment, etc.).
`type` resolves to `invflux_ref_types.code`.

- `idAsString(): string`

### `MetaEvent` (final class)

One configuration / schema audit event written to the config ledger.

```php
__construct(
    public readonly string $eventType,
    public readonly array $payload = [],
    public readonly ?ActorReference $actor = null,
    public readonly ?EntityReference $reference = null,
    public readonly ?\DateTimeImmutable $recordedAt = null,
)
```

- `recordedAt(): \DateTimeImmutable` — memoized

### `QuantityGuard` (final class)

Commit-time min/max check for a subject-slot update.

```php
__construct(public readonly int $min = 0, public readonly ?int $max = null)
```

Protects against stale reads after SlotFlow has already computed deltas: if the live
row's projected quantity falls outside `[min, max]`, persistence fails with a
`PersistenceConflict` and the entire write is aborted.

---

## 8. Results (`Nandan108\InvFlux\Results`)

### `PersistedMovement` (final class)

```php
__construct(
    public readonly bool $ok,
    public readonly int $affectedSlots,
    public readonly int $insertedLedgerRows,
    public readonly \DateTimeImmutable $recordedAt,
    public readonly array $conflicts = [],   // list<PersistenceConflict>
)
```

### `PersistedBatchMovement` (final class)

```php
__construct(
    public readonly bool $ok,
    public readonly int $affectedSubjects,
    public readonly int $affectedSlots,
    public readonly int $insertedLedgerRows,
    public readonly \DateTimeImmutable $recordedAt,
    public readonly array $conflicts = [],
)
```

### `PersistenceConflict` (final class)

```php
__construct(
    public readonly SubjectId $subjectId,
    public readonly string $slotKey,
    public readonly string $reason,
    public readonly string $currentQuantity,
    public readonly string $delta,
    public readonly string $projectedQuantity,
    public readonly ?string $minQuantity = null,
    public readonly ?string $maxQuantity = null,
)
```

Quantities are passed as strings for adapter-side decimal precision.

### `BookingOutcome` (string enum)

| Case | Value | Meaning |
|---|---|---|
| `BOOKED_RESERVED` | `booked_reserved` | Reservation succeeded, no recovery needed |
| `BOOKED_RECOVERED` | `booked_recovered` | Quantity recovered from another slot |
| `BOOKED_RECOVERED_PARTIAL` | `booked_recovered_partial` | Partial capture; remainder unmet |
| `RECOVERY_FAILED` | `recovery_failed` | Recovery attempt failed |
| `FAILED` | `failed` | Booking failed outright |

---

## 9. Read models (`Nandan108\InvFlux\ReadModels`)

### `InventoryBalance` (final class)

```php
__construct(
    public readonly SubjectId $subjectId,
    public readonly int $slotId,
    public readonly string $slotKey,
    public readonly array $dimensions,    // array<string, string>
    public readonly string $quantity,     // adapter-precision decimal as string
)
```

### `LedgerRecord` (final class)

```php
__construct(
    public readonly int $id,
    public readonly SubjectId $subjectId,
    public readonly string $movementTypeOwnerKey,
    public readonly string $movementTypeCode,
    public readonly ?string $fromSlotKey,
    public readonly ?array $fromDimensions,
    public readonly ?string $toSlotKey,
    public readonly ?array $toDimensions,
    public readonly int $quantity,
    public readonly ?int $initialFrom,
    public readonly ?int $initialTo,
    public readonly ?string $referenceType,
    public readonly ?string $referenceId,
    public readonly ?string $actorTypeCode,
    public readonly ?string $actorId,
    public readonly \DateTimeImmutable $recordedAt,
)
```

Creation has no `fromSlotKey`; destruction has no `toSlotKey`.

---

## 10. Storage contracts (`Nandan108\InvFlux\Contracts`)

### `InventoryStore` (interface)

Aggregate contract implemented by complete adapters. Composed of:

```php
extends SchemaManager, ConfigManager, InventoryReader, InventoryWriter,
        TypeRegistry, MetaLedger, TransactionalStore, IdempotencyStore,
        SubjectRegistrar, SystemRegistrar, IdentifierTypeRegistrar
```

### `SchemaManager`

- `bootstrap(LayeredSlotSpaceDefinition|SlotSpaceDefinition $definition): void`
- `schema(): SlotSpaceDefinition` — throws if not bootstrapped
- `addDimensionValues(string $dimensionName, array $values): void`
- `drainAndDisableDimensionValue(string $dimensionName, string $sourceValue, ?string $targetValue = null): void`

### `ConfigManager`

- `quantityScale(): int`
- `setQuantityScale(int $scale): void` — only before any data exists
- `migrateQuantityScale(int $targetScale): void`

### `InventoryReader`

- `inventoryBalances(SubjectId $subjectId, array $slotFilters = []): list<InventoryBalance>`
- `ledger(SubjectId $subjectId, array $slotFilters = [], int $limit = 100, int $offset = 0): list<LedgerRecord>`

### `InventoryWriter`

- `persist(PersistMovement $movement): PersistedMovement`
- `persistBatch(PersistBatchMovement $movement): PersistedBatchMovement`

### `TypeRegistry`

- `registerMovementTypes(string $ownerKey, array $movementTypes): void` — `list<MovementTypeDefinition>`
- `registerActorTypes(array $actorTypes): void` — `list<ActorTypeDefinition>`
- `registerRefTypes(string $ownerKey, array $refTypes): void` — `list<RefTypeDefinition>`
- `registerSurfaceTypes(string $ownerKey, array $surfaceTypes): void` — `list<SurfaceTypeDefinition>`
- `resolveRefTypeId(string $code): ?int`
- `resolveSurfaceTypeId(string $code): ?int`

### `MetaLedger`

- `recordMetaEvent(MetaEvent $event): void`

### `TransactionalStore`

- `transactional(\Closure $operation): mixed` — nested calls share the outer transaction
- `withAdvisoryLock(string $lockName, int $timeoutSeconds, \Closure $callback): mixed`

### `IdempotencyStore`

- `executeIdempotent(IdempotencyKey $key, \Closure $operation): IdempotentExecution`

### `SubjectRegistrar`

- `registerSubject(?SubjectId $parentId = null, SubjectKind $kind = SubjectKind::Unit): SubjectId`
- `claimIdentifier(SubjectId, string $typeCode, string $systemSlug, string $value, ?ActorReference $scopeActor = null, bool $isPrimary = false, ?\DateTimeImmutable $validFrom = null): void`
- `expireIdentifier(string $typeCode, string $systemSlug, string $value, ?ActorReference $scopeActor = null, ?\DateTimeImmutable $validTo = null): int`
- `replaceSubjectIdentifier(SubjectId, string $typeCode, string $systemSlug, string $oldValue, string $newValue, ?ActorReference $scopeActor = null, ?\DateTimeImmutable $changedAt = null): void`
- `reclaimExpiredIdentifier(SubjectId, string $typeCode, string $systemSlug, string $value, ?ActorReference $scopeActor = null): void`
- `resolveIdentifier(string $typeCode, string $systemSlug, string $value, ?ActorReference $scopeActor = null, ?\DateTimeImmutable $asOf = null): ?SubjectId`
- `resolveIdentifierAssignment(...): ?SubjectIdentifierAssignment`
- `listSubjectIdentifiers(SubjectId, ?string $systemSlug = null, ?\DateTimeImmutable $asOf = null): list<SubjectIdentifierAssignment>`
- `resolveSubjectKind(SubjectId $id): ?SubjectKind`

### `SystemRegistrar`

- `registerSystem(string $slug, string $name, ?string $plugin = null): void` — idempotent

### `IdentifierTypeRegistrar`

- `registerIdentifierType(string $code, string $name, ?string $category = null): void`
- `configureIdentifierAssignment(string $systemSlug, string $typeCode, IdentifierAssignmentPolicy $policy, ?string $scopeActorTypeCode = null): void`

---

## 11. Order domain (`Nandan108\InvFlux\Domain\Order`)

InvFlux's primary aggregate. Orders are projected from external source systems
(WooCommerce, Shopify, ERP) into a canonical representation. Driven by the
dispatch workbench, the corrections engine, and the refund queue.

### `Order` (final class extends attrecord Record) → `invflux_orders`

| Property | Type | Notes |
|---|---|---|
| `id` | `?string` | BINARY(16) UUIDv7 PK, minted in `beforeSave()` |
| `source_system` | `string` | UNIQUE composite |
| `external_id` | `string` | UNIQUE composite |
| `status` | `int` | `OrderStatus` value (TINYINT UNSIGNED) |
| `stock_state` | `int` | adapter-defined bitmask |
| `line_count` | `int` | |
| `staged_count` | `int` | |
| `shipped_count` | `int` | |
| `unprocessed_corrections` | `int` | |
| `late_days` | `int` | |
| `est_delivery` | `?\DateTimeImmutable` | |
| `customer_id` | `?int` | |
| `customer_name` | `string` | |
| `customer_email` | `string` | |
| `shipping_address_hash` | `?string` | |
| `workflow_state` | `int` | `OrderWorkflowState` value |
| `created_at` | `?\DateTimeImmutable` | |
| `updated_at` | `?\DateTimeImmutable` | |

Lifecycle hooks: `beforeSave()` sets `created_at`/`updated_at`; `validate()` runs
per-save assertion. Natural key: `(source_system, external_id)`.

### `OrderStatus` (int-backed enum)

| Case | Value | Meaning |
|---|---|---|
| `Incomplete` | 0 | Lines exist, none staged |
| `Staged` | 1 | At least one line staged; not all shipped |
| `Shipped` | 2 | Per line: `qty_ordered − qty_shipped − qty_corrected == 0`. Terminal positive state |
| `Cancelled` | 3 | Cancelled before fulfilment (matched against external order status) |

### `OrderWorkflowState` (int-backed enum)

| Case | Value | Meaning |
|---|---|---|
| `Active` | 0 | In the active dispatch queue |
| `OnHold` | 1 | Held out of the active queue pending resolution |
| `Parked` | 2 | Set aside by a worker; resumable |

### `OrderLine` (final class) → `invflux_order_lines`

| Property | Type | Notes |
|---|---|---|
| `id` | `?string` | BINARY(16) UUIDv7 PK, minted in `beforeSave()` |
| `order_id` | `?string` | BINARY(16) FK orders, CASCADE; UNIQUE composite |
| `external_line_ref` | `string` | UNIQUE composite |
| `subject_id` | `int` | FK subjects |
| `name` | `string` | snapshot at order time |
| `sku` | `string` | snapshot |
| `gtin` | `?string` | snapshot |
| `image_url` | `?string` | snapshot |
| `unit_price` | `string` | DECIMAL(10,2), order currency; the price **before** discount |
| `line_discount` | `string` | DECIMAL(10,2), the discount off the whole line (`0.00` when none); mirrors the host document. `refundFor(qty)` prorates `unit_price × qty_ordered − line_discount` cumulatively over corrected units, so the pieces sum to the line's net total |
| `qty_ordered` | `int` | |
| `qty_corrected` | `int` | |
| `qty_shipped` | `int` | |
| `qty_staged` | `int` | |
| `staged_by` | `?int` | actor id |
| `staged_at` | `?\DateTimeImmutable` | |
| `staged_source` | `?string` | |
| `stock_state` | `int` | |
| `po_id` | `?int` | optional FK to purchase order |

### `OrderCharge` (final class) → `invflux_order_charges`

One order-level charge per source charge item — shipping's amount, a payment fee, packing, a
delivery option. The source is the only writer of the money; `kind` is decided on first projection
and never recomputed. Lock tier 47.

| Property | Type | Notes |
|---|---|---|
| `id` | `?string` | BINARY(16) UUIDv7 PK, minted in `beforeSave()` |
| `order_id` | `?string` | BINARY(16) FK orders, CASCADE; UNIQUE composite |
| `external_ref` | `string` | the source item id; UNIQUE composite |
| `kind` | `OrderChargeKind` | VARCHAR(16) via `EnumCaster`, default `other` |
| `name` | `string` | what the customer was shown |
| `amount` | `string` | DECIMAL(10,2), before tax, order currency; **signed** — a discount recorded as a charge is negative |
| `tax` | `string` | DECIMAL(10,2) |

### `OrderChargeKind` (string enum)

`freight`, `rush`, `packing`, `handling`, `financing`, `payment`, `other`. `unclCode(): ?string` gives
the UNCL 7161 reason code (`FC`, `AAT`, `PC`, `HD`, `FI`; null for `payment` and `other`).

### `OrderPayment` (final class) → `invflux_order_payments`

One payment received against an order. A record, never a stock state: what it decides reaches the
stock only through the status the order is then given. **Voided, never deleted** — the void fields
are the only ones written after insert. Lock tier 48.

| Property | Type | Notes |
|---|---|---|
| `id` | `?string` | BINARY(16) UUIDv7 PK, minted in `beforeSave()` |
| `order_id` | `?string` | BINARY(16) FK orders, CASCADE |
| `amount` | `string` | DECIMAL(10,2), in `currency`; always positive (money going back out is a refund) |
| `currency` | `string` | VARCHAR(3), ISO 4217 |
| `fx_rate` | `?string` | DECIMAL(18,8), order-currency units per payment-currency unit; null when the currencies match |
| `amount_order_ccy` | `string` | DECIMAL(10,2), **derived on save** from `amount` × `fx_rate` (= `amount` with no rate); the figure every sum reads |
| `method` | `string` | VARCHAR(64), the gateway id or a manual method code |
| `source` | `string` | VARCHAR(32), a code registered with `PaymentSources` |
| `shipment_id` | `?string` | BINARY(16) FK shipments, SET NULL; the parcel a per-parcel collection was made against |
| `transaction_ref` | `?string` | VARCHAR(191), gateway transaction id / transfer, cheque or remittance reference |
| `received_at` | `?\DateTimeImmutable` | when the money arrived; required |
| `difference` | `?string` | DECIMAL(10,2), what was accepted as settled beyond what arrived (positive = less arrived); requires `difference_reason` |
| `difference_reason` | `?string` | VARCHAR(32), `bank_fee` / `rounding` / `fx` / … |
| `recorded_by` | `int` | actor id, 0 = system |
| `recorded_at` | `?\DateTimeImmutable` | set in `beforeSave()` |
| `note` | `?string` | TEXT |
| `voided_at`, `voided_by`, `void_reason` | nullable | set together by `void(actorId, reason, at)` or not at all |

Helpers: `isVoided()`, `void()` (refuses a second void and an empty reason), `convertedAmount()`,
`static paidTotal(list<OrderPayment>): string` — the sum of the unvoided `amount_order_ccy` (the
money); `settledAmount()` and `static settledTotal(list<OrderPayment>): string` — the same plus each
accepted `difference`. **What an order still owes is its total less `settledTotal`**, so a shortfall
accepted as settled is owed by nobody.

### `PaymentTolerance` (final class, value)

How far a payment may differ from what an order owes and still settle it:
`new PaymentTolerance(absolute = '0.00', ?percent = null)`, the smaller limit governing (the
percentage of what is owed rounds down); `exact()` is zero. `allowedFor(owed): string`,
`accepts(owed, received): bool` — either side of what is owed. Throws `\InvalidArgumentException` on a
negative limit or a percentage over 100.

### `PaymentDifferenceReason` / `PaymentDifferenceReasons`

Why a payment that differs from what the order owes still settles it — `OrderPayment::$difference_reason`.
Each reason implies a different posting (bank charge, FX gain/loss, rounding, a credit held for the
customer), so the codes are stable and an accounting add-on maps them to its ledgers.
`PaymentDifferenceReason` holds core's codes, each with the side of what is owed it can explain:
`bank_fee` (shortfall only), `fx`, `rounding`, `other` (either), `overpaid` (excess only).
`PaymentDifferenceReasons` is the registry writers check against: `register(code, shortfall = true,
excess = true)` (lower-case identifier ≤ 32 chars, at least one side; re-registering replaces the
sides), `has()`, `explainsShortfall()`, `explainsExcess()`, `all()`.

`payment.recorded` and `payment.voided` are **monetary** events (`OrderEventType::isMonetary()`, tier
Lifecycle): a `MonetaryEventPayload` in the order's currency, base amount resolved as of the day the
money arrived; `extras` carry the payment id, source, method, what arrived before conversion
(`payment_amount`, `payment_currency`, `payment_fx_rate`), the `difference` and `difference_reason`, and
the reference.

### `PaymentSource` / `PaymentSources`

`PaymentSource` holds the codes core writes: `gateway` (copied in from the gateway), `manual` (an
operator recorded it), `host_status` (inferred from the host order status, where the payment method's
policy says so). They do not close the set: `PaymentSources` is the registry a writer checks —
built-ins plus any an add-on `register()`s (lower-case identifier, ≤ 32 characters); `has()`, `all()`.

### `OrderCorrection` (final class) → `invflux_order_corrections`

Per-line correction record; drives both inventory movement and (optionally) refund.

| Property | Type | Notes |
|---|---|---|
| `id` | `?string` | BINARY(16) UUIDv7 PK, minted in `beforeSave()` via `RecordIdentity` |
| `order_id` | `?string` | BINARY(16) FK orders |
| `line_id` | `?string` | BINARY(16) FK order_lines |
| `type_id` | `int` | TINYINT FK order_correction_types |
| `reason_id` | `?int` | TINYINT FK order_correction_reasons |
| `qty` | `int` | SMALLINT, quantity being corrected |
| `refund_amount` | `string` | DECIMAL(10,2) as string |
| `refund_mode` | `string` | `RefundMode` value |
| `note` | `?string` | TEXT |
| `created_by` | `int` | actor id (0 = system) |
| `created_at` | `?\DateTimeImmutable` | |
| `processed_at` | `?\DateTimeImmutable` | null = unprocessed; indexed |
| `refund_scheduled_at` | `?\DateTimeImmutable` | |
| `refund_executed_at` | `?\DateTimeImmutable` | |
| `refund_cancelled_at` | `?\DateTimeImmutable` | |
| `manual_refund_done_at` | `?\DateTimeImmutable` | |
| `manual_refund_done_by` | `?int` | actor id |
| `stock_issue_id` | `?int` | links to stock issue if reported |
| `correlation_id` | `?string` | CHAR(26) ULID, indexed; groups related events |
| `parent_event_id` | `?string` | BINARY(16) FK OrderEvent.id (SetNull on delete); the *decision-tier* event that authorised this correction. Indexed. |
| `refund_attempts` | `int` | |
| `next_attempt_at` | `?\DateTimeImmutable` | |

Helpers: `isUnprocessed(): bool`, `beforeSave()` (mints id + sets created_at),
`validate()` (16-byte binary checks on order_id/line_id/parent_event_id, RefundMode/qty
sanity).

`ledger_entry_id` was dropped (it violated the ledger-is-pure-referrer
invariant). The ledger row that processed a correction is found via
`(ref_type='order_event', ref_id=<event.id>)` through the `correction.created` event.

Lifecycle:

```
proposed (parent_event_id NULL)
    → bundled (parent_event_id set to a decision-tier event id, e.g. lpsc.resolved)
    → processed (processed_at set; engine wrote ledger row)
    → [if refund_mode != none] queued in refund pipeline
    → refund_executed | refund_cancelled
```

Essentials tier today goes straight from `proposed → bundled` in the same call: the LPSC
writer emits `lpsc.resolved` first, then creates corrections with `parent_event_id`
already set. The proposed phase is the future MPB-style CS workflow seam: floor staff
create proposals with `parent_event_id = NULL`, CS reviews + bundles them, the bundle
gets a decision event, and the bundling code stamps each correction.

### `OrderCorrectionType` (final class) → `invflux_order_correction_types`

Seeded registry of correction shapes.

| Property | Type | Notes |
|---|---|---|
| `id` | `?int` | TINYINT UNSIGNED |
| `code` | `string` | UNIQUE |
| `name` | `string` | |
| `pre_dispatch` | `bool` | slot move is `oh.ctd → oh.atp` if true |
| `restock` | `bool` | resaleable back to stock if true |
| `refund` | `bool` | owes refund if true |

The four-flag combination determines slot routing for the correction.

### `OrderCorrectionReason` (final class) → `invflux_order_correction_reasons`

Seeded registry of reasons.

| Property | Type | Notes |
|---|---|---|
| `id` | `?int` | TINYINT UNSIGNED |
| `code` | `string` | UNIQUE |
| `name` | `string` | |
| `cause` | `string` | `Cause` value — whose fault the correction is; the only place fault is recorded |
| `timing` | `CorrectionTiming` | ENUM `pre` / `post` / `any`, default `any`: when the reason can apply relative to shipment. `appliesTo(bool $preDispatch)` |
| `gl_posting_hint` | `?array` | LONGTEXT JSON; suggested keys `{debit_account?, credit_account?, tax_register?, notes?}`; consumed by the future GL projector. Reason-level hint typically overrides the type-level hint when both are set. |

`OrderCorrectionType` carries the same optional `gl_posting_hint ?array` column
with identical semantics — see [§OrderCorrectionType](#ordercorrectiontype-final-class--invflux_order_correction_types).

### `TaxLine` (final class) → `invflux_tax_lines`

One per-tax-rate snapshot polymorphically attachable to any money-flow entity.
This section is the shape reference; the broader tax design rationale lives
with the adapter's tax documentation.

| Property      | Type            | Notes                                                       |
|---------------|-----------------|-------------------------------------------------------------|
| `id`          | `?string`       | BINARY(16) UUIDv7 PK, minted via `RecordIdentity`           |
| `parent_type` | `string`        | Discriminator: must be in `TaxLine::KNOWN_PARENT_TYPES`. Today: `"correction"`. Future: `"order_line"`, `"shipment"`, `"po_line"`, `"supplier_claim"`. New parents append to the const — no schema migration. Note returns are corrections (post-shipment `OrderCorrection` types), NOT a separate parent. |
| `parent_id`   | `?string`       | BINARY(16) UUIDv7 of the parent entity. **Not a hard FK** — MySQL doesn't natively enforce polymorphic FKs; integrity is application-side. |
| `tax_code`    | `string`        | VARCHAR(64). Source-system tax-rate id (WC `tax_rate_id` today; Shopify line tax id / manual code for future adapters). |
| `net`         | `string`        | DECIMAL(10,2). Pre-tax amount on this rate share.            |
| `tax_rate`    | `string`        | DECIMAL(9,4). Percentage (e.g. `"19.0000"`). Default `0.0000`; populated when a consumer needs the human-readable rate. |
| `tax_amount`  | `string`        | DECIMAL(10,2). Tax owed on this rate share.                  |
| `created_at`  | `?\DateTimeImmutable` | Mint time                                              |

Composite index `(parent_type, parent_id)` for "all tax lines for X" reads.

`validate()`: enforces `parent_type` in `KNOWN_PARENT_TYPES`, `parent_id` is
16-byte binary, `tax_code` is non-empty. `beforeSave()`: mints id + sets
`created_at`.

No repository interface today — saved directly via attrecord (write-only need;
no read consumer yet). Add a `TaxLineRepository` when a read path appears
(dispatch workbench timeline, GL projector, supplier-claim audit). The adapter
helper `CorrectionService::attachTaxLines()` stamps `parent_type='correction',
parent_id=<correction.id>` for callers passing the optional `?array $taxLines`
arg; `LpscCorrectionWriter` does not pass it today by design (refund.executed
event payload carries the authoritative refunded tax).

### `Cause` (string enum)

| Case | Value |
|---|---|
| `Customer` | `customer` |
| `Merchant` | `merchant` |
| `Logistics` | `logistics` |

### `RefundMode` (string enum)

| Case | Value | Behavior |
|---|---|---|
| `None` | `none` | No refund |
| `Manual` | `manual` | Human marks `manual_refund_done_at` |
| `Auto` | `auto` | Scheduled-refund executor dispatches automatically |

### `OrderEvent` (final class) → `invflux_order_events`

Append-only timeline of dispatch-scoped order actions. The store exposes only `append`
+ readers — there is intentionally no update path. Reversals are new events with the
same `correlation_id`.

| Property | Type | Notes |
|---|---|---|
| `id` | `?string` | BINARY(16) UUIDv7 PK, minted in `beforeSave()` |
| `order_id` | `?string` | BINARY(16) FK orders |
| `event_type` | `string` | VARCHAR(40), `OrderEventType` value |
| `event_tier` | `string` | ENUM('lifecycle','decision','execution','ancillary'); auto-derived in `beforeSave()` and `validate()` from `OrderEventType::tier()`. Schema invariant; explicit mismatch throws. |
| `occurred_at` | `?\DateTimeImmutable` | business-time (defaults to `recorded_at`) |
| `recorded_at` | `?\DateTimeImmutable` | server-time (CURRENT_TIMESTAMP(6) default) |
| `actor_id` | `?int` | FK actors |
| `surface_id` | `?int` | FK surfaces |
| `ref_type_id` | `?int` | FK ref_types |
| `ref_id` | `?string` | BINARY(16) — UUID-keyed referent only; INT-keyed refs go through dedicated typed columns elsewhere |
| `amount` | `?string` | DECIMAL(10,2) |
| `currency` | `?string` | CHAR(3); required when `amount` is set (validate enforces) |
| `flags` | `?string` | JSON |
| `payload` | `?string` | JSON; polymorphic by `event_type` |
| `note` | `?string` | VARCHAR(500) |
| `correlation_id` | `?string` | CHAR(26) ULID, indexed; links related events |

`ledger_entry_id` was dropped (ledger-is-pure-referrer invariant). The ledger row
produced by an event is found via `(ref_type='order_event', ref_id=<event.id>)` using
the existing `idx_ref` index on `invflux_inventory_ledger`.

### `OrderEventTier` (string enum)

Coarse 4-way classification of every `OrderEventType`. Lets readers (timeline UIs,
GL projectors, reporting) group events without enumerating each type.

| Case | Value | Role |
|---|---|---|
| `Lifecycle` | `lifecycle` | State-machine transitions — `order.created`, `correction.created`, `shipment.sent`, … |
| `Decision` | `decision` | Resolutions/approvals that authorise downstream Execution events; the natural `parent_event_id` target for `OrderCorrection` |
| `Execution` | `execution` | Concrete monetary or stock effects of a decision — `refund.executed`, `refund.failed`, future `gl.posted` |
| `Ancillary` | `ancillary` | Side-band annotations — `note.added`, `email.sent/suppressed`, `stock_issue.reported`, future `alert.raised` |

### `OrderEventType` (string enum)

```
order.created, order.assigned, order.parked, order.unparked,
order.locked, order.unlocked,
shipment.prepared, shipment.sent, shipment.cancelled,
correction.created, correction.unprocessed,
lpsc.resolved,
refund.executed, refund.failed, refund.cancelled_before_execution,
payment.recorded, payment.voided,
note.added, email.sent, email.suppressed,
stock_issue.reported
```

Each case maps to exactly one `OrderEventTier` via `tier(): OrderEventTier`. Notes:

- `lpsc.resolved` is Decision-tier; emitted by `LpscCorrectionWriter` once per
  resolution (cancel-fully or accept-partial). Carries `{resolution, execute_at}` in
  payload; correction ids are reverse-traversable via `correction.parent_event_id`.
- `refund.cancelled_before_execution` is the pre-dispatch cancellation; `refund.voided`
  is reserved for a future post-execution contra-entry.
- `correction.processed` and `refund.scheduled` were removed: processing is a
  state-column transition (`processed_at` + `parent_event_id` presence), and the refund
  schedule is carried by the decision event's `execute_at` payload field.

### Repositories

All implemented by `MysqlDomainStore` via attrecord.

- `OrderRepository` — `findById`, `findByExternalRef(source, externalId)`, `save`
- `OrderShippingRepository` — `forOrder(orderId)`, `forOrders(list<orderId>)`, `saveAll(list)`,
  `deleteAll(list)`: an order's shipping arrangements, reconciled as a set per order, bulk only.
- `OrderChargeRepository` — the same four methods, for an order's `OrderCharge` rows.
- `OrderPaymentRepository` — `findById`, `forOrder(orderId)`, `forOrders(list<orderId>)` (voided
  payments included — the list is the payment history), `saveAll(list)` (a void is an update).
- `OrderLineRepository` — `findById`, `findByExternalRef(orderId, externalLineRef)`,
  `forOrder(orderId)`, `save`
- `OrderCorrectionRepository` — `findById`, `forOrder`, `unprocessed`,
  `findOrderIdsWithDueRefunds(dueBeforeOrAt, orderLimit)`,
  `findDueRefundsForOrder(orderId, dueBeforeOrAt)`, `save`, `delete`
- `OrderCorrectionTypeRepository` — `findByCode`, `all`
- `OrderCorrectionReasonRepository` — `findById`, `findByCode`, `all`
- `OrderEventStore` — `append(OrderEvent)`, `forOrder(orderId, limit=200)`,
  `byCorrelation(correlationId)`

### Built-in catalogs

- `BuiltInCorrectionTypes::all(): list<OrderCorrectionType>` — 9 canonical types
  (cancellations, write-offs, shortfalls, returns)
- `BuiltInCorrectionReasons::all(): list<OrderCorrectionReason>` — 15 canonical
  reasons across `customer | merchant | logistics`. `retired(): list<string>` — codes no longer
  offered (storage removes a retired row nothing records); `systemOnly(): list<string>` — codes only
  the system records (`shortfall_disclosed`, `shortfall_undisclosed`); `writesOff(string $code): bool`
  — before shipment, the reason alone decides between writing the units off (`defective`,
  `short_pick`) and putting them back
- `PreShipmentWriteOff::allows(string $reasonCode, PreShipmentStock $stock): bool` — per reason:
  `defective` needs no substitute (`atp + res = 0`); `short_pick` also needs the shelf to hold nothing
  for other orders (`ctd − stagedElsewhere − lineClaim ≤ 0`); a reason that does not write off is never
  refused. A refused write-off is an on-hand correction. `PreShipmentStock` (`atp`, `res`, `ctd`,
  `demand` — paid orders' unshipped units — `stagedElsewhere`, `lineClaim`; `substitutes()`,
  `shortfall()` — `demand − ctd`, and 0 while anything is for sale or reserved — `onShelfForOthers()`)
  carries the figures. `shortfall()` is also the most an "out of stock" cancellation may cancel.
  `BuiltInCorrectionReasons::writingOff(): list<string>` lists the reasons it applies to
- `BuiltInOrderRefTypes` — `const OWNER_KEY = 'order'`;
  `static all(): list<RefTypeDefinition>` — exposes `order_line`, `correction`,
  `order_event`

---

## 12. Policy (`Nandan108\InvFlux\Policy`)

Essentials-tier policy switches. Carried as plain value objects; consumed by adapter
behavior. An add-on may replace them with dedicated workflows.

### `BookingPolicy`

```php
__construct(public readonly bool $cancelOnShortage = true)
```

### `ConflictHandlingPolicy`

```php
__construct(public readonly bool $logConflicts = true)
```

### `ReleasePolicy`

```php
__construct(public readonly bool $releaseExpiredReservations = true)
```

### `ReservationPolicy`

```php
__construct(public readonly bool $failWholeReservation = true)
```

---

## 13. Idempotency (`Nandan108\InvFlux\Idempotency`)

Replay-safe execution for external business operations.

### `IdempotencyKey` (final class)

```php
__construct(public readonly string $scope, public readonly string $operationKey)
```

### `IdempotentOutcome` (final class)

```php
__construct(
    public readonly string $outcomeCode,
    public readonly array $payload = [],
    public readonly bool $terminal = true,
)

static terminal(string $outcomeCode, array $payload = []): self
static retryable(string $outcomeCode, array $payload = []): self
```

### `IdempotentExecution` (final class)

```php
__construct(
    public readonly bool $replayed,         // true if reuse of stored outcome
    public readonly bool $terminal,
    public readonly string $outcomeCode,
    public readonly array $payload = [],
    public readonly ?\DateTimeImmutable $completedAt = null,
)
```

Semantics: `IdempotencyStore::executeIdempotent` returns the stored outcome when a
key is replayed; otherwise it runs the closure, persists the resulting
`IdempotentOutcome`, and returns it wrapped as a fresh execution
(`$replayed === false`).

---

## 14. Projection (`Nandan108\InvFlux\Projection`)

Extension point for transactional projections that must update synchronously with the
inventory write. Authoritative state is still the inventory store; projections are
derived read models or downstream platform updates that need to lock the same rows.

### `ProjectionParticipant` (interface)

```php
key(): string
collectLockTargets(ProjectionContext $context): list<ProjectionLockTarget>
lock(ProjectionContext $context, array $targets): void
apply(ProjectionContext $context): void
postCommit(): void
```

### `ProjectionContext` (final class)

```php
__construct(
    public readonly array $deltaRows,                // list<array>
    public readonly array $lockedInventoryRows,      // list<array>
    public readonly \DateTimeImmutable $recordedAt,
    public readonly string $movementTypeOwnerKey,
    public readonly string $movementTypeCode,
    public readonly ?string $referenceType,
    public readonly ?string $referenceId,
    public readonly ?string $actorTypeCode,
    public readonly ?string $actorId,
    public readonly ?SurfaceReference $surface = null,
    public readonly ?object $runtime = null,
)
```

### `ProjectionLockTarget` (final class)

```php
__construct(public readonly string $resourceType, public readonly string $resourceKey)
```

---

## 15. Registry (`Nandan108\InvFlux\Registry`)

Type-definition value objects. Adapters seed these into the controlled-vocabulary
tables (`movement_types`, `actor_types`, `ref_types`, `surface_types`).

### `MovementTypeDefinition` (final class)

```php
__construct(
    public readonly string $code,
    public readonly string $name,
    public readonly ?string $description = null,
    public readonly bool $active = true,
)
```

### `ActorTypeDefinition` (final class)

Same shape as MovementTypeDefinition.

### `RefTypeDefinition` (final class)

```php
__construct(public readonly string $code, public readonly string $name)
```

### `SurfaceTypeDefinition` (final class)

```php
__construct(
    public readonly string $code,
    public readonly string $name,
    public readonly ?string $description = null,
)
```

---

## 16. Diagnostics (`Nandan108\InvFlux\Diagnostics`)

Health checks with optional auto-repair.

### `DiagnosticCheck` (interface)

```php
key(): string
defaultFrequencySeconds(): int
run(): DiagnosticResult
repair(DiagnosticResult $result): ?DiagnosticResult
```

### `DiagnosticResult` (final class)

```php
__construct(
    public readonly DiagnosticStatus $status,
    public readonly array $findings = [],   // list<array>
    public readonly int $durationMs = 0,
)

isOk(): bool
```

### `DiagnosticStatus` (string enum)

`Ok = 'ok'`, `AutoRepaired = 'auto_repaired'`, `Unresolvable = 'unresolvable'`.

---

## 17. Exceptions (`Nandan108\InvFlux\Exceptions`)

All extend `InvFluxException` (abstract, extends `RuntimeException`). Most carry a
stable `$detailCode` and a `$context` array.

| Class | Purpose | Notable props |
|---|---|---|
| `ConfigurationException` | Invalid configuration / malformed inputs | `detailCode='configuration_error'` |
| `PersistenceException` | Storage failure (not a business conflict) | `detailCode='persistence_error'` |
| `SchemaException` | Schema bootstrap / consistency failure | `detailCode='schema_error'` |
| `InvalidFilterException` | Unknown / inactive dimension filter | `dimension` |
| `InvalidQuantityException` | Quantity not normalizable for persistence | `field`, `quantity` |
| `UnknownMovementTypeException` | Movement type not registered | `ownerKey`, `movementCode` |
| `UnknownActorTypeException` | Actor type not registered | `actorTypeCode` |
| `IdentifierAlreadyClaimedException` | `claimIdentifier` collision under `uniqueActiveValue` | `detailCode='identifier_already_claimed'` |
| `IdentifierValueRetiredException` | Historical row for different subject under `reusableAfterExpiry=false` | `detailCode='identifier_value_retired'` |
| `ActiveIdentifierNotFoundException` | `replaceSubjectIdentifier` old value not active | `detailCode='active_identifier_not_found'` |
| `AmbiguousIdentifierAssignmentException` | Multiple active assignments for same key | `detailCode='ambiguous_identifier_assignment'` |

---

## 18. Utilities (`Nandan108\InvFlux\Util`)

### `Ulid`

```php
static generate(): string       // current time + crypto-random
static isValid(string $value): bool
```

### `Clock`

```php
now(): \DateTimeImmutable
```

Use this rather than `new \DateTimeImmutable()` inside services so tests can swap.

### `Json`

```php
static encode(mixed $value): string             // throws JsonException
static decodeObject(string $json): array        // assoc-array decode
```

### `ArrayCompactor`

```php
static compact(array $items, array $keys = []): array
```

Wire-format encoder: compresses `list<assoc>` into `[headers, ...rows]` for REST
responses that ship many uniform rows.

### `Assert`

```php
static nonEmptyString(string $value, string $message): string
```

---

## 19. MySQL Adapter — Table shapes (`Nandan108\InvFlux\MysqlStorage`)

Reference implementation. Two stores share one `MysqlSession` so compound
transactions are atomic across inventory and domain writes.

### Lock acquisition order

Within any transaction touching both stores, locks must be acquired in this order:

1. **attrecord domain entity locks** via `LockSet::acquire()` (tier-ordered,
   ascending PK within each tier).
2. **`MysqlInventoryStore` inventory state locks** via its internal temp-table +
   `SELECT FOR UPDATE` pattern.

Never reverse. Most flows derive inventory rows from a known domain entity (PO line,
shipment), so domain-first is also the code-flow path of least resistance.

### Tier table

| Tier | Record | Table |
|---|---|---|
| 10 | SubjectWorksheetRecord | invflux_subject_worksheets |
| 11 | SubjectWorksheetItemRecord | invflux_subject_worksheet_items |
| 20 | Order | invflux_orders |
| 21 | OrderLine | invflux_order_lines |
| 22 | OrderCorrection | invflux_order_corrections |
| 23 | OrderEvent | invflux_order_events |
| 24 | TaxLine | invflux_tax_lines |

### A. Inventory tables (`MysqlInventoryStore`)

All names are prefixed with the configured WP table prefix (e.g. `wp_invflux_…`).
Engine `InnoDB`, charset `utf8mb4`, collation `utf8mb4_unicode_ci`.

#### `invflux_dimensions`

| Column | Type | Notes |
|---|---|---|
| id | INT UNSIGNED PK AI | |
| name | VARCHAR(64) | UNIQUE |
| position_index | INT UNSIGNED | UNIQUE |
| kind | ENUM('partition','reveal') | |
| collapse_behavior | ENUM(`aggregate|drop_hidden|forbid_if_nonempty|archive_then_drop|map_to_value`) | |
| collapse_target_value | VARCHAR(64) NULL | |
| default_value | INT UNSIGNED NULL | FK → dimension_values |
| active | TINYINT(1) DEFAULT 1 | |
| metadata_json | JSON | |

#### `invflux_dimension_values`

| Column | Type | Notes |
|---|---|---|
| id | INT UNSIGNED PK AI | |
| dimension_id | INT UNSIGNED | FK dimensions CASCADE |
| code | VARCHAR(64) | UNIQUE per dimension |
| name | VARCHAR(191) NULL | |
| owner_key | VARCHAR(32) DEFAULT 'core' | |
| active | TINYINT(1) DEFAULT 1 | |
| removal_target_code | VARCHAR(64) NULL | |
| metadata_json | JSON | |
| parent_id | INT UNSIGNED NULL | self-referential |
| path | VARCHAR(512) DEFAULT '' | materialized ancestor path |
| addressable | TINYINT(1) DEFAULT 1 | |
| level | VARCHAR(32) NULL | structural role |

Indexes: `uniq_dimension_code`, `idx_dimension_level`.

#### `invflux_layers`

| Column | Type | Notes |
|---|---|---|
| id | SMALLINT UNSIGNED PK AI | |
| slug | VARCHAR(50) | UNIQUE |
| name | VARCHAR(100) | |

#### `invflux_slotspace`

| Column | Type | Notes |
|---|---|---|
| id | BIGINT UNSIGNED PK AI | |
| layer_id | SMALLINT UNSIGNED NULL | FK layers SET NULL |
| slot_key | VARCHAR(191) | UNIQUE |
| active | TINYINT(1) DEFAULT 1 | |
| metadata_json | JSON | |

Indexes: `uniq_slot_key`, `idx_active_slot`, `idx_layer_slot`.

#### `invflux_config_state` (singleton)

| Column | Type | Notes |
|---|---|---|
| id | TINYINT UNSIGNED PK | hardcoded = 1 |
| quantity_scale | SMALLINT UNSIGNED DEFAULT 0 | decimal places |
| active_dimensions_json | JSON | |
| layers_json | JSON NULL | |
| updated_at | DATETIME(6) | ON UPDATE CURRENT_TIMESTAMP |

#### `invflux_subjects`

| Column | Type | Notes |
|---|---|---|
| id | BIGINT UNSIGNED PK AI | |
| parent_id | BIGINT UNSIGNED NULL | FK self CASCADE |
| kind | ENUM('aggregate','unit','batch') DEFAULT 'unit' | |
| product_id | BIGINT UNSIGNED NULL | FK self CASCADE; root self-reference |
| variant_id | BIGINT UNSIGNED NULL | FK self CASCADE |
| reorder_threshold | SMALLINT UNSIGNED NULL | |
| reorder_threshold_source | ENUM('manual','rop_calculated') NULL | |
| stock_managed | TINYINT(1) DEFAULT 1 | |
| created_at | DATETIME(6) | |

Indexes: `idx_parent`, `idx_product_kind`, `idx_variant_kind`.

#### `invflux_inventory_state` — authoritative current quantity

| Column | Type | Notes |
|---|---|---|
| subject_id | BIGINT UNSIGNED | FK subjects CASCADE |
| slot_id | BIGINT UNSIGNED | FK slotspace CASCADE |
| quantity | INT UNSIGNED DEFAULT 0 | scale-adjusted by `config_state.quantity_scale` |
| updated_at | TIMESTAMP | ON UPDATE CURRENT_TIMESTAMP |

PK `(subject_id, slot_id)`. Index `idx_slot_subject (slot_id, subject_id)`.

> **Removed:** the former `invflux_layer_totals` write-through cache (one row per
> `(subject_id, layer_id)`) was dropped — it cost a round-trip on every movement and was read
> only by the cross-layer-drift diagnostic, which recomputes the authoritative totals from
> `invflux_inventory_state` regardless.

#### Type registries

`invflux_movement_types` — `id SMALLINT UNSIGNED PK AI, owner_key VARCHAR(15), code
VARCHAR(32), name VARCHAR(64), description VARCHAR(255) NULL, active TINYINT(1)`.
UNIQUE `(owner_key, code)`.

`invflux_actor_types` — `id TINYINT UNSIGNED PK AI, code VARCHAR(32) UNIQUE, name
VARCHAR(64), description VARCHAR(255) NULL, active TINYINT(1)`.

`invflux_actors` — `id INT UNSIGNED PK AI, actor_type_id TINYINT UNSIGNED FK
actor_types RESTRICT, actor_ref VARCHAR(64), uuid BINARY(16) NULL`. UNIQUE
`(actor_type_id, actor_ref)`.

`invflux_ref_types` — `id SMALLINT UNSIGNED PK AI, code VARCHAR(32) UNIQUE, name
VARCHAR(64)`. Built-ins: `order, purchase_order, shipment, return, transfer,
stock_take, ad_hoc, schema_change, dimension_value, setting`.

`invflux_surface_types` — `id TINYINT UNSIGNED PK AI, code VARCHAR(32) UNIQUE, name
VARCHAR(64), description VARCHAR(255) NULL`. Built-ins: `admin_page, api, cli,
plugin, system, import, job`.

`invflux_surfaces` — `id SMALLINT UNSIGNED PK AI, surface_type_id TINYINT UNSIGNED FK
surface_types RESTRICT, surface_ref VARCHAR(191), parent_id SMALLINT UNSIGNED NULL
FK self RESTRICT, name VARCHAR(255) NULL, active TINYINT(1), metadata_json JSON`.
UNIQUE `(surface_type_id, surface_ref)`.

#### `invflux_inventory_ledger` — immutable movement log

| Column | Type | Notes |
|---|---|---|
| id | BIGINT UNSIGNED PK AI | |
| subject_id | BIGINT UNSIGNED | FK subjects RESTRICT |
| movement_type_id | SMALLINT UNSIGNED | FK movement_types RESTRICT |
| from_slot_id | BIGINT UNSIGNED NULL | FK slotspace SET NULL; null = creation |
| to_slot_id | BIGINT UNSIGNED NULL | FK slotspace SET NULL; null = destruction |
| quantity | INT UNSIGNED | |
| initial_from | INT UNSIGNED NULL | balance snapshot before move |
| initial_to | INT UNSIGNED NULL | |
| ref_type_id | SMALLINT UNSIGNED NULL | FK ref_types RESTRICT |
| ref_id | BINARY(16) NULL | UUIDv7 of a hot-path-mintable referent. INT-keyed referents (subjects, dimension values, …) go through dedicated typed columns; not this column. |
| actor_id | INT UNSIGNED NULL | FK actors SET NULL |
| surface_id | SMALLINT UNSIGNED NULL | FK surfaces SET NULL |
| recorded_at | DATETIME(6) | |

Indexes: `idx_subject_recorded`, `idx_movement_type`, `idx_ref`, `idx_actor`,
`idx_surface`, `idx_from_slot_recorded`, `idx_to_slot_recorded`.

#### `invflux_schema_ledger` — schema-change audit

| Column | Type | Notes |
|---|---|---|
| id | BIGINT UNSIGNED PK AI | |
| event_type | VARCHAR(32) | |
| actor_id | INT UNSIGNED NULL | FK actors SET NULL |
| ref_type_id | SMALLINT UNSIGNED NULL | FK ref_types RESTRICT |
| ref_id | BIGINT UNSIGNED NULL | |
| payload_json | JSON | |
| recorded_at | DATETIME(6) | |

Indexes: `idx_event_type_recorded`, `idx_actor`, `idx_ref`.

#### `invflux_stock_adjustments`

| Column | Type | Notes |
|---|---|---|
| id | BINARY(16) PK | UUIDv7, minted by the adapter Record's `beforeSave()` via `RecordIdentity`. Per arch-uuid-identity §4.1 (hot-path-mintable). |
| source | ENUM(`manual|csv|excel|paste|workbench|plugin|plugin_intercept`) | |
| surface_id | SMALLINT UNSIGNED NULL | FK surfaces SET NULL |
| operator_id | BIGINT UNSIGNED | |
| reason | TEXT NULL | |
| file_name | VARCHAR(255) NULL | |
| row_count | INT UNSIGNED NULL | |
| created_at | DATETIME | |

Linked from `inventory_ledger.ref_id` when `ref_type = stock_adjustment` (ref_type
registered by the adapter, not core).

#### Subject identity tables

`invflux_identifier_types` — `id SMALLINT UNSIGNED PK AI, code VARCHAR(50) UNIQUE,
name VARCHAR(100), category VARCHAR(50) NULL`.

`invflux_systems` — `id SMALLINT UNSIGNED PK AI, slug VARCHAR(100) UNIQUE, name
VARCHAR(150), plugin VARCHAR(100) NULL`.

`invflux_subject_identifiers` — time-windowed mapping.

| Column | Type | Notes |
|---|---|---|
| id | BIGINT UNSIGNED PK AI | |
| subject_id | BIGINT UNSIGNED | FK subjects CASCADE |
| type_id | SMALLINT UNSIGNED | FK identifier_types |
| system_id | SMALLINT UNSIGNED | FK systems |
| value | VARCHAR(255) | |
| value_uint | BIGINT UNSIGNED NULL | GENERATED VIRTUAL, `value` as a number or NULL |
| is_primary | TINYINT(1) NULL | |
| scope_actor_id | INT UNSIGNED NULL | FK actors |
| scope_actor_key | INT UNSIGNED | GENERATED `IFNULL(scope_actor_id, 0)` |
| valid_from | DATETIME(6) | |
| valid_to | DATETIME(6) DEFAULT `'9999-12-31 23:59:59.999999'` | |

UNIQUE `(system_id, type_id, scope_actor_key, value, valid_to)`. Indexes:
`idx_identifier_resolve_window`, `idx_identifier_subject_window`, `idx_value_uint`.

**Join numeric identifiers on `value_uint`, never on `CAST(value AS UNSIGNED)`.** An adapter whose
host keys rows by an integer — a WordPress `post_id`, a PrestaShop `id_product` — joins its own
tables to this one on the identifier value, and those hosts store `114697` and `0114697` as the
same row, so the comparison has to be numeric. Casting at the join site makes it an expression, and
an expression is not sargable: the planner scans the whole identifier table once per outer row.
`value_uint` is the same number as a column, so the index applies. On a 22k-product store the
difference is a reconciliation pass measured in minutes rather than seconds, and an onboarding read
that does not finish at all.

`value_uint` is `NULL` for a `value` that is not a run of 1–19 digits — a SKU, a GTIN with a check
character, anything alphanumeric. That is deliberate: `CAST` maps every one of those to `0` (or to
a leading numeric prefix, so `311064-NERO` becomes `311064`), which both collapses the index into
one enormous bucket and invents matches. `NULL` never joins, which is the honest answer for an
identifier that is not a number.

`invflux_identifier_assignment_policies` — rules per `(system, type, scope_actor_type)`.

| Column | Type | Notes |
|---|---|---|
| system_id | SMALLINT UNSIGNED | FK systems |
| type_id | SMALLINT UNSIGNED | FK identifier_types |
| scope_actor_type_id | TINYINT UNSIGNED NULL | FK actor_types |
| scope_actor_type_key | TINYINT UNSIGNED | GENERATED |
| unique_active_value | TINYINT(1) DEFAULT 0 | |
| reusable_after_expiry | TINYINT(1) DEFAULT 1 | |
| lifecycle_anchor | TINYINT(1) DEFAULT 0 | |
| mutable_alias | TINYINT(1) DEFAULT 1 | |

UNIQUE `(system_id, type_id, scope_actor_type_key)`.

#### `invflux_idempotency`

| Column | Type | Notes |
|---|---|---|
| id | BIGINT UNSIGNED PK AI | |
| scope | VARCHAR(32) | |
| operation_key | VARCHAR(191) | |
| outcome_code | VARCHAR(32) NULL | |
| payload_json | JSON | |
| created_at | DATETIME(6) | |
| completed_at | DATETIME(6) NULL | |

UNIQUE `(scope, operation_key)`. Index `idx_completed_at`.

### B. Domain tables (`MysqlDomainStore`)

#### `invflux_orders` — see § 11 for column ↔ property map

UNIQUE `(source_system, external_id)`. Indexes `idx_status`, `idx_workflow_state`.

#### `invflux_order_lines`

UNIQUE `(order_id, external_line_ref)`. Indexes `idx_subject`, `idx_po`. FK `order_id`
CASCADE.

#### `invflux_order_correction_types` (seeded)

`id TINYINT UNSIGNED PK AI, code VARCHAR(40) UNIQUE, name VARCHAR(96), pre_dispatch
TINYINT(1), restock TINYINT(1), refund TINYINT(1)`.

#### `invflux_order_correction_reasons` (seeded)

`id TINYINT UNSIGNED PK AI, code VARCHAR(40) UNIQUE, name VARCHAR(96), cause
ENUM('customer','merchant','logistics')`.

#### `invflux_order_corrections`

Columns as listed in § 11 OrderCorrection. FKs:
- `order_id` → orders CASCADE
- `line_id` → order_lines CASCADE
- `type_id` → correction_types RESTRICT
- `reason_id` → correction_reasons SET NULL
- `parent_event_id` → order_events SET NULL (the decision-tier event that authorised
  this correction; e.g. `lpsc.resolved` from LpscCorrectionWriter)

Indexes: `idx_order`, `idx_line`, `idx_refund_queue`, `idx_processed`,
`idx_correlation`, `idx_parent_event`.

Install order matters: `invflux_order_events` is created **before**
`invflux_order_corrections` so the `parent_event_id` FK can resolve. See
`MysqlOrderStore::ORDER_RECORDS`.

#### `invflux_order_events`

Columns as listed in § 11 OrderEvent. FKs:
- `order_id` → orders CASCADE
- `actor_id` → actors SET NULL
- `surface_id` → surfaces SET NULL
- `ref_type_id` → ref_types SET NULL

Indexes: `idx_order_time`, `idx_event_time`, `idx_ref`, `idx_correlation`,
`idx_actor`, `idx_surface`.

#### `invflux_tax_lines`

Columns as listed in § 11 TaxLine. **No FKs** — `(parent_type, parent_id)` is a
polymorphic reference; integrity is application-side. attrecord DDL emits the
table with no FOREIGN KEY constraints.

Indexes: `idx_parent` (composite `(parent_type, parent_id)`).

Install order: created last in `MysqlOrderStore::ORDER_RECORDS` (after
OrderCorrection and OrderEvent) — though strictly it doesn't depend on either
since there are no hard FKs.

#### `invflux_subject_worksheets`

| Column | Type | Notes |
|---|---|---|
| id | BIGINT UNSIGNED PK AI | |
| uuid | BINARY(16) NULL | |
| name | VARCHAR(255) NULL | |
| description | TEXT NULL | |
| owner_actor_id | INT UNSIGNED | |
| visibility | VARCHAR(16) DEFAULT 'private' | `private|shared|site` |
| status | VARCHAR(16) DEFAULT 'active' | `active|archived` |
| workflow_type | VARCHAR(64) NULL | |
| workflow_doc_id | BIGINT UNSIGNED NULL | |
| payload_json | JSON NULL | |
| metadata_json | JSON NULL | |
| created_at | DATETIME NULL | |
| created_by_actor_id | INT UNSIGNED | |
| updated_at | DATETIME NULL | |
| updated_by_actor_id | INT UNSIGNED | |

Indexes: `idx_owner`, `idx_status_visibility`, `idx_workflow`.

#### `invflux_subject_worksheet_items`

| Column | Type | Notes |
|---|---|---|
| id | BIGINT UNSIGNED PK AI | |
| worksheet_id | BIGINT UNSIGNED | FK worksheets CASCADE |
| subject_kind | VARCHAR(64) | |
| subject_id | BIGINT UNSIGNED | |
| quantity | DECIMAL(15,4) NULL | |
| payload_json | JSON NULL | |
| created_at | DATETIME NULL | |
| created_by_actor_id | INT UNSIGNED | |
| updated_at | DATETIME NULL | |
| updated_by_actor_id | INT UNSIGNED | |

UNIQUE `(worksheet_id, subject_kind, subject_id)`. Index `idx_subject`.

### C. Store class APIs

#### `MysqlSession` (extends attrecord `DbSession`)

Adds: `defaultCollation(): string`.

Inherited from `DbSession`:

- `exec(string $sql, array $params = []): int`
- `fetchAll(string $sql, array $params = []): list<array>`
- `fetchOne(string $sql, array $params = []): ?array`
- `fetchScalar(string $sql, array $params = []): string|int|float|null`
- `lastInsertId(): string|int`
- `transactional(\Closure $operation): mixed`
- `withAdvisoryLock(string $lockName, int $timeoutSeconds, \Closure $callback): mixed`
- `inTransaction(): bool`
- `isDuplicateKeyError(\Throwable $e): bool`

#### `MysqlInventoryStore` (implements `InventoryStore`)

**Schema / bootstrap**

- `bootstrap(LayeredSlotSpaceDefinition|SlotSpaceDefinition $definition): void`
- `schema(): SlotSpaceDefinition`
- `setQuantityScale(int $scale): void`
- `migrateQuantityScale(int $targetScale): void`
- `quantityScale(): int`

**Dimensions**

- `addDimensionValues(string $dimensionName, array $values): void`
- `drainAndDisableDimensionValue(string $dimensionName, string $sourceValue, ?string $targetValue = null): void`

**Type registries**

- `registerMovementTypes(string $ownerKey, array $movementTypes): void`
- `registerActorTypes(array $actorTypes): void`
- `registerRefTypes(string $ownerKey, array $refTypes): void`
- `registerSurfaceTypes(string $ownerKey, array $surfaceTypes): void`

**Projection participants**

- `registerProjectionParticipant(ProjectionParticipant $participant): void`

**Meta ledger**

- `recordMetaEvent(MetaEvent $event): void`

**Persistence**

- `persist(PersistMovement $movement): PersistedMovement`
- `persistBatch(PersistBatchMovement $movement): PersistedBatchMovement`
- `executeBatchFlowFromStorage(StorageBatchFlowRequest $request): PersistedBatchMovement`
- `executeBatchBoundaryFlowFromStorage(StorageBoundaryFlowRequest $request): PersistedBatchMovement`

**Reads**

- `inventoryBalances(SubjectId $subjectId, array $slotFilters = []): list<InventoryBalance>`
- `ledger(SubjectId $subjectId, array $slotFilters = [], int $limit = 100, int $offset = 0): list<LedgerRecord>`
- `schemaDefinitionAtTime(\DateTimeImmutable $at): array` — reconstruct historical schema

**Subjects**

- `registerSubject(?SubjectId $parentId = null, SubjectKind $kind = SubjectKind::Unit): SubjectId`
- `resolveSubjectKind(SubjectId $id): ?SubjectKind`

**Identifiers**

- `claimIdentifier(SubjectId, string $typeCode, string $systemSlug, string $value, ?ActorReference $scopeActor = null, bool $isPrimary = false, ?\DateTimeImmutable $validFrom = null): void`
- `expireIdentifier(...): void`
- `replaceSubjectIdentifier(SubjectId, string $typeCode, string $systemSlug, string $oldValue, string $newValue, ?ActorReference $scopeActor = null, bool $isPrimary = false): void`
- `reclaimExpiredIdentifier(...)`
- `resolveIdentifier(string $typeCode, string $systemSlug, string $value, ?ActorReference $scopeActor = null, ?\DateTimeImmutable $asOf = null): ?SubjectId`
- `resolveIdentifierAssignment(...): ?SubjectIdentifierAssignment`
- `listSubjectIdentifiers(SubjectId, string $typeCode, string $systemSlug, ?ActorReference $scopeActor = null): list<SubjectIdentifierAssignment>`
- `registerSystem(string $slug, string $name, ?string $plugin = null): void`
- `registerIdentifierType(string $code, string $name, ?string $category = null): void`
- `configureIdentifierAssignment(string $systemSlug, string $typeCode, ?string $scopeActorTypeCode = null, bool $uniqueActiveValue = false, bool $reusableAfterExpiry = true, bool $lifecycleAnchor = false, bool $mutableAlias = true): void`

**Actor / surface resolution**

- `resolveActorId(string $typeCode, ?string $actorRef): int`
- `resolveSurfaceId(string $typeCode, string $ref, ?int $parentId = null): int`
- `resolveRefTypeId(string $code): ?int`
- `resolveSurfaceTypeId(string $code): ?int`

**Transaction control**

- `transactional(\Closure $operation): mixed`
- `withAdvisoryLock(string $lockName, int $timeoutSeconds, \Closure $callback): mixed`
- `executeIdempotent(IdempotencyKey $key, \Closure $operation): IdempotentExecution`

#### `MysqlDomainStore`

- `bootstrap(): void` — configures attrecord Record classes; creates domain tables (idempotent)
- `transactional(\Closure $operation): mixed` — shares session with `MysqlInventoryStore`
- `tablePrefix(): string`

CRUD on Order, OrderLine, OrderCorrection, OrderCorrectionType,
OrderCorrectionReason, OrderEvent, SubjectWorksheetRecord, SubjectWorksheetItemRecord
goes through attrecord `Record::save() / ::find() / ::delete()` and `RecordSet::saveAll()`.

---

## 20. Design boundaries (recap)

- **SlotFlow computes; InvFlux owns schema, persistence, audit, projections.**
- **Dimension value `code` is identity. `name` is display.** Order is not persisted
  domain state — sort by `code` unless a UI or policy chooses otherwise.
- **Layer selection is explicit** through `DimensionValueSelector`. No layer
  inference from depth.
- **Business priority is flow policy**, not dimension or value declaration order.
- **Projections and layer totals are derived state.** Authoritative inventory is
  `(subject_id, slot_id) → quantity` in `invflux_inventory_state`.
- **Locks always go domain-first, inventory-second.** Any reversal — even on an
  edge case — is a deadlock risk.
- **MysqlInventoryStore uses raw SQL** (no attrecord). `MysqlDomainStore` uses
  attrecord for domain entity CRUD. They share one `MysqlSession`.
- **Idempotency** is per-`(scope, operationKey)` and persists `IdempotentOutcome`;
  replays return the recorded outcome without re-running.
