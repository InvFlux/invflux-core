<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Contracts\Inventory;

use Nandan108\InvFlux\Layer\LayeredSlotSpaceDefinition;
use Nandan108\InvFlux\Schema\DimensionValueDefinition;
use Nandan108\InvFlux\Schema\SlotSpaceDefinition;

/**
 * Bootstrap and inspect the declared slot-space schema.
 *
 * @api
 */
interface SchemaManager
{
    /**
     * Bootstrap the storage schema for a layered or single slot-space definition.
     *
     * Passing a plain SlotSpaceDefinition is backwards-compatible; it is wrapped
     * internally as a single-layer LayeredSlotSpaceDefinition named "default".
     */
    public function bootstrap(LayeredSlotSpaceDefinition | SlotSpaceDefinition $definition): void;

    /** Return the currently bootstrapped slot-space definition. */
    public function schema(): SlotSpaceDefinition;

    /**
     * The **live** definition for one layer: its flows, over the dimension values actually
     * registered on this install.
     *
     * The distinction that matters is against a definition built from code alone. A shared
     * dimension declares no values in the definition — they live in the database, because add-ons
     * and merchants add them — so a caller that compiles the raw definition gets a slot space with
     * the states and locations someone wrote down once, not the ones this install has. Anything
     * executing a flow needs the latter.
     *
     * Flows come from the bootstrapped definition, because flows are code and are never persisted.
     *
     * @psalm-param non-empty-string $layerName
     *
     * @throws \Nandan108\InvFlux\Exceptions\SchemaException when the layer is not bootstrapped
     */
    public function layerSchema(string $layerName): SlotSpaceDefinition;

    /**
     * Add or reactivate values in one existing dimension.
     *
     * **Strictly additive.** A value's `code` must sit under its parent's — `oh/main` under `oh` —
     * and re-declaring an existing value with different metadata is refused rather than applied.
     * Giving an *addressable* value its first child is refused too: it changes what that value
     * means, and the change is not one this call can infer. Use {@see hierarchiseDimensionValue()},
     * which asks for the missing decision.
     *
     * @param non-empty-string               $dimensionName
     * @param list<DimensionValueDefinition> $values
     *
     * @throws \Nandan108\InvFlux\Exceptions\ConfigurationException when a code does not sit under
     *                                                              its parent's, when a value is
     *                                                              redefined, or when an
     *                                                              addressable value would gain a
     *                                                              first child
     */
    public function addDimensionValues(string $dimensionName, array $values): void;

    /**
     * Deepen a hierarchical dimension by one level: demote an existing value into a child
     * position and mint a new parent above it, taking the code it vacates.
     *
     * Stock and history stay with the **demoted** value, because they are anchored to its slot
     * ids rather than to its code. Nothing is copied and no movement is recorded: the ledger
     * re-reads under the longer name, so a movement written when the value was `oh` reads as
     * `oh/main` afterwards. The interposed parent is minted empty and has no history of its own.
     *
     * That retroactive relabelling is truthful — the location did not move, it acquired a longer
     * name — but it is invisible without a record, so the operation writes a `location_hierarchised`
     * row to the schema ledger. That row is what explains a movement rendering under a code minted
     * after it, and it is the reason this is an audited operation rather than a rename.
     *
     * ## Both levels are stated, never inferred
     *
     * "A warehouse gains bins" and "the on-hand root gains warehouses" are the *same* structural
     * event with opposite answers about which value keeps the old level, so the caller states
     * both. Registering bins demotes `oh` to `oh/unassigned` at bin level and gives `warehouse`
     * to the interposed parent; registering warehouses demotes `oh` to `oh/main` which *keeps*
     * `warehouse`, and the interposed parent takes a grouping level above it. Passing the wrong
     * pair yields two values at one level, which a level-matching layer selects twice.
     *
     * Exactly one parent is interposed: `$demotedCode` must be `$code` plus one path segment.
     * Moving an existing value to a different parent is a different operation and is not this one.
     *
     * @param non-empty-string      $dimensionName
     * @param non-empty-string      $code          the value to hierarchise; keeps its slot ids and
     *                                             its stock, and is renamed to `$demotedCode`
     * @param non-empty-string      $demotedCode   where it lands — `$code` plus one segment
     * @param non-empty-string|null $demotedLevel  structural level for the demoted value
     * @param non-empty-string|null $parentLevel   structural level for the interposed parent
     * @param non-empty-string|null $demotedName   display name for the demoted value; its existing
     *                                             name is kept when null
     * @param non-empty-string|null $parentName    display name for the interposed parent
     *
     * @throws \Nandan108\InvFlux\Exceptions\SchemaException when the value is unknown or inactive,
     *                                                       when `$demotedCode` is not one segment
     *                                                       under `$code`, or when it is already taken
     */
    public function hierarchiseDimensionValue(
        string $dimensionName,
        string $code,
        string $demotedCode,
        ?string $demotedLevel,
        ?string $parentLevel,
        ?string $demotedName = null,
        ?string $parentName = null,
    ): void;

    /**
     * Declare which value is a dimension's default.
     *
     * Only meaningful for dimensions whose values are loaded from storage (`sharedRef`): a
     * statically-defined dimension declares its default in code. Without this, the default for
     * such a dimension falls back to the first value in code order — deterministic, but
     * semantically arbitrary, so registering `oh/dock1` alongside `oh/main` would silently move
     * it. Declaring it makes the default a decision rather than an artefact of sort order.
     *
     * Idempotent, and never inferred: callers state the value they mean.
     *
     * @param non-empty-string $dimensionName
     * @param non-empty-string $valueCode     must name an existing, active value of that dimension
     */
    public function setDimensionDefault(string $dimensionName, string $valueCode): void;

    /**
     * Move all quantities out of one dimension value, then disable it.
     *
     * @param non-empty-string      $dimensionName
     * @param non-empty-string      $sourceValue
     * @param non-empty-string|null $targetValue   one of a concrete value, `_default`, `_nil`, or null for automatic resolution
     */
    public function drainAndDisableDimensionValue(string $dimensionName, string $sourceValue, ?string $targetValue = null): void;
}
