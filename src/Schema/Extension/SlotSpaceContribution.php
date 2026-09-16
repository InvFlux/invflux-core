<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Schema\Extension;

use Nandan108\InvFlux\Exceptions\ConfigurationException;
use Nandan108\InvFlux\Flow\FlowDefinition;
use Nandan108\InvFlux\Layer\LayeredSlotSpaceDefinition;
use Nandan108\InvFlux\Registry\BaseMovementType;
use Nandan108\InvFlux\Registry\MovementTypeDefinition;
use Nandan108\InvFlux\Schema\DimensionValueDefinition;
use Nandan108\InvFlux\Schema\SlotConfinement;
use Nandan108\SlotFlow\Rules\SlotRule;

/**
 * The builder a {@see SlotSpaceContributor} writes into.
 *
 * Contributions accumulate as *operations* and are applied to the immutable base definition only at
 * {@see build()}. That ordering is what stops one contributor from observing — or clobbering —
 * another's half-finished work mid-pass: while `contribute()` runs, the definition has not moved.
 *
 * Every operation is attributed to whichever contributor is running, so a failure names the add-on
 * rather than the line of framework code that noticed.
 *
 * @api
 */
final class SlotSpaceContribution
{
    /** @var array<non-empty-string, array<non-empty-string, DimensionValueDefinition>> dimension => code => value */
    private array $requiredValues = [];

    /** @var array<non-empty-string, non-empty-string> dimension\0code => contributor key */
    private array $valueOwners = [];

    /** @var list<SlotRule> */
    private array $rules = [];

    /** @var list<SlotConfinement> */
    private array $confinements = [];

    /** @var list<array{layer: non-empty-string, flow: FlowDefinition, contributor: non-empty-string}> */
    private array $flows = [];

    /** @var array<non-empty-string, array<non-empty-string, MovementTypeDefinition>> ownerKey => code => type */
    private array $movementTypes = [];

    private EventBindingRegistry $bindings;

    /** @var list<array{binding: EventBinding}> deferred: a binding is validated once every flow is folded */
    private array $pendingBindings = [];

    /** @psalm-var non-empty-string */
    private string $currentContributor = 'core';

    private int $currentPriority = 0;

    /**
     * @param array<non-empty-string, list<non-empty-string>> $knownValues values already registered for a
     *                                                                     dimension whose values live in the database rather than in the definition — `stt`'s native set,
     *                                                                     the seeded `loc` values. A `sharedRef` dimension declares no inline values, so without this the
     *                                                                     assembler cannot tell an add-on's typo from a value the merchant registered last week, and a
     *                                                                     dimension absent here is simply **not** value-checked rather than checked wrongly.
     */
    public function __construct(
        private readonly LayeredSlotSpaceDefinition $base,
        private readonly array $knownValues = [],
    ) {
        $this->bindings = new EventBindingRegistry();
    }

    /**
     * Attribute everything recorded from here on to one contributor.
     *
     * Called by the assembler around each `contribute()`; a contributor never calls it, which is
     * why attribution cannot be spoofed by one add-on claiming another's key.
     *
     * @internal
     *
     * @psalm-param non-empty-string $key
     */
    public function forContributor(string $key, int $priority): void
    {
        $this->currentContributor = $key;
        $this->currentPriority = $priority;
    }

    /**
     * Declare dimension values this contributor's flows need to exist.
     *
     * These land in the **persisted** plane — `addDimensionValues()` at provisioning, additive and
     * idempotent — not in the definition. Two contributors may name the same value (a shared
     * `trs/inb` leg); declaring it twice is fine, *redefining* it is not, and is refused here
     * rather than at provisioning where the two declarations are no longer distinguishable.
     */
    public function requireValues(string $dimension, DimensionValueDefinition ...$values): self
    {
        '' !== $dimension || throw new ConfigurationException(
            sprintf('Contributor "%s" declared values for an unnamed dimension.', $this->currentContributor),
            'empty_dimension_name',
        );

        foreach ($values as $value) {
            $ownerKey = $dimension."\0".$value->code;
            $existing = $this->requiredValues[$dimension][$value->code] ?? null;

            if (null !== $existing && !self::sameValue($existing, $value)) {
                throw new ConfigurationException(
                    sprintf(
                        'Contributors "%s" and "%s" declare "%s" in dimension "%s" differently.',
                        $this->valueOwners[$ownerKey] ?? 'unknown',
                        $this->currentContributor,
                        $value->code,
                        $dimension,
                    ),
                    'conflicting_dimension_value',
                    ['dimension' => $dimension, 'value' => $value->code],
                );
            }

            /** @psalm-suppress PropertyTypeCoercion — codes are non-empty by DimensionValueDefinition's own guard */
            $this->requiredValues[$dimension][$value->code] = $value;
            $this->valueOwners[$ownerKey] ??= $this->currentContributor;
        }

        return $this;
    }

    /**
     * Declare placement rules — the deny-rules that prune impossible or segregated slots.
     *
     * Rules are additive and never removed: a later contributor cannot relax an earlier one's
     * restriction, which is deliberate. "`pnd` cannot exist inside `oh/*`" is a statement about
     * what the value *means*, and an add-on that wants it relaxed wants a different value.
     */
    public function requireRules(SlotRule ...$rules): self
    {
        foreach ($rules as $rule) {
            $this->rules[] = $rule;
        }

        return $this;
    }

    /**
     * Constrain where one of your dimension values may sit — the placement primitive contributors
     * should reach for first.
     *
     * `confine('stt', 'pnd', 'loc', ['sup', 'trs/inb'])` reads *"pending stock exists only at the
     * supplier or on the inbound leg"*, and that is all it can ever mean. It removes the slots
     * carrying `pnd` whose location falls outside that list, and touches nothing else.
     *
     * **Prefer this to {@see requireRules()} for anything about your own values**, because a
     * constraint composes and a rule sequence does not. Confinements intersect and commute: two
     * over different values cannot interact, two over the same value both hold, and the same one
     * twice changes nothing — so where you land in the assembled order is irrelevant. A rule
     * sequence, by contrast, has a base and an order that no single contributor controls, and its
     * worst failure is silent: an inclusion arriving first over an empty base yields a layer with
     * no valid slots at all.
     *
     * A confinement also cannot do that. The worst a wrong one does is remove the slots for its own
     * value, which shows up as "my stock has nowhere to go" rather than as a store that has quietly
     * stopped having inventory.
     *
     * Routed per layer at {@see build()}: it applies to the layers carrying **both** dimensions,
     * and a confinement no layer can host is refused rather than silently doing nothing.
     *
     * @param non-empty-string       $dimension dimension owning the constrained value
     * @param non-empty-string       $value     the value whose placement is constrained
     * @param non-empty-string       $axis      the dimension the constraint reads
     * @param list<non-empty-string> $allowed   the only `$axis` values `$value` may sit on
     */
    public function confine(string $dimension, string $value, string $axis, array $allowed): self
    {
        $this->confinements[] = new SlotConfinement(
            dimension: $dimension,
            value: $value,
            axis: $axis,
            allowed: $allowed,
            contributor: $this->currentContributor,
        );

        return $this;
    }

    /**
     * Add a flow to one layer. Add-only: a duplicate name is refused when the operations are
     * folded, because {@see \Nandan108\InvFlux\Schema\SlotSpaceDefinition::withFlow()} refuses it.
     *
     * There is no `overrideFlow()`, and that is the design rather than an omission — what an
     * add-on changes is which flow an *event* runs ({@see bindEvent()}), never what a named flow
     * means.
     *
     * @psalm-param non-empty-string $layer
     */
    public function addFlow(string $layer, FlowDefinition $flow): self
    {
        $this->flows[] = ['layer' => $layer, 'flow' => $flow, 'contributor' => $this->currentContributor];

        return $this;
    }

    /**
     * Add one same-named flow across several layers, so a boundary operation moves both planes
     * together.
     *
     * @param array<non-empty-string, FlowDefinition> $layerFlows layer name => that layer's half
     */
    public function boundaryFlow(array $layerFlows): self
    {
        foreach ($layerFlows as $layer => $flow) {
            $this->addFlow($layer, $flow);
        }

        return $this;
    }

    /**
     * Declare movement types this contributor's flows are recorded under.
     *
     * They must exist before the first movement: the ledger FKs into `invflux_movement_types`, so
     * an unregistered code is a foreign-key failure at the worst possible moment rather than a
     * configuration error at boot.
     *
     * A bare code is accepted, and then the code is also its ledger label — fine for an internal
     * type, poor for one a merchant reads. Pass a {@see MovementTypeDefinition} to say what it is
     * called.
     *
     * @psalm-param non-empty-string $ownerKey
     */
    public function registerMovementTypes(string $ownerKey, string | MovementTypeDefinition ...$types): self
    {
        foreach ($types as $type) {
            $definition = $type instanceof MovementTypeDefinition
                ? $type
                : new MovementTypeDefinition($type, $type);

            /** @psalm-var non-empty-string $code — guaranteed by MovementTypeDefinition's own guard */
            $code = $definition->code;
            $existing = $this->movementTypes[$ownerKey][$code] ?? null;
            // An explicit label already given wins over a bare code, whichever arrived first.
            $this->movementTypes[$ownerKey][$code] = null !== $existing && $existing->name !== $existing->code
                ? $existing
                : $definition;
        }

        return $this;
    }

    /**
     * Bind a domain event to a flow and the movement type it is recorded under.
     *
     * Validated at {@see build()}, not here: a contributor may legitimately bind an event to a flow
     * a *later* contributor adds, and refusing on declaration order would make the outcome depend
     * on priority for a reason that has nothing to do with priority.
     *
     * A movement type is `(owner_key, code)`, so an add-on rebinding an event to a type of its own
     * passes the same `$ownerKey` it used at {@see registerMovementTypes()}. Omitted means core's
     * set — the right default, since every binding that ships names a type core registered.
     *
     * @psalm-param non-empty-string      $event
     * @psalm-param non-empty-string      $flow             a flow registered on any layer
     * @psalm-param non-empty-string      $movementTypeCode
     * @psalm-param non-empty-string|null $expect           contributor this binding believes owns the event today
     * @psalm-param non-empty-string      $ownerKey         owner of the movement type named above
     */
    public function bindEvent(
        string $event,
        string $flow,
        string $movementTypeCode,
        ?string $expect = null,
        string $ownerKey = BaseMovementType::OWNER_KEY,
    ): self {
        $this->pendingBindings[] = ['binding' => new EventBinding(
            event: $event,
            flow: $flow,
            movementTypeCode: $movementTypeCode,
            contributorKey: $this->currentContributor,
            priority: $this->currentPriority,
            expect: $expect,
            movementTypeOwnerKey: $ownerKey,
        )];

        return $this;
    }

    /**
     * Fold every recorded operation onto the base definition and validate the result.
     *
     * @throws ConfigurationException on an unknown slot value, a literal `loc` in a pattern, a
     *                                duplicate flow name, or a binding naming no registered flow
     */
    public function build(): LayeredSlotSpaceDefinition
    {
        $definition = $this->base;

        foreach ($this->flows as $addition) {
            $layer = $addition['layer'];
            if (!isset($definition->layers[$layer])) {
                throw new ConfigurationException(
                    sprintf(
                        'Contributor "%s" adds flow "%s" to unknown layer "%s".',
                        $addition['contributor'],
                        $addition['flow']->name(),
                        $layer,
                    ),
                    'unknown_layer',
                    ['layer' => $layer, 'contributor' => $addition['contributor']],
                );
            }

            $this->assertPatternsAreSatisfiable($definition, $layer, $addition['flow'], $addition['contributor']);

            $layers = $definition->layers;
            try {
                $layers[$layer] = $layers[$layer]->withFlow($addition['flow']);
            } catch (ConfigurationException $e) {
                throw new ConfigurationException(
                    sprintf(
                        'Contributor "%s" cannot add flow "%s" to layer "%s": %s',
                        $addition['contributor'],
                        $addition['flow']->name(),
                        $layer,
                        $e->getMessage(),
                    ),
                    $e->detailCode,
                    $e->context + ['contributor' => $addition['contributor']],
                    $e,
                );
            }
            $definition = LayeredSlotSpaceDefinition::define($layers);
        }

        $definition = $this->foldPlacement($definition);

        $this->foldBindings($definition);

        return $definition;
    }

    /** @return array<non-empty-string, list<DimensionValueDefinition>> dimension => values, de-duplicated */
    public function requiredValues(): array
    {
        $out = [];
        foreach ($this->requiredValues as $dimension => $byCode) {
            ksort($byCode);
            $out[$dimension] = array_values($byCode);
        }
        ksort($out);

        return $out;
    }

    /** @return array<non-empty-string, list<MovementTypeDefinition>> ownerKey => types, code-sorted */
    public function movementTypes(): array
    {
        $out = [];
        foreach ($this->movementTypes as $ownerKey => $byCode) {
            ksort($byCode);
            $out[$ownerKey] = array_values($byCode);
        }
        ksort($out);

        return $out;
    }

    public function bindings(): EventBindingRegistry
    {
        return $this->bindings;
    }

    /**
     * Route placement rules and confinements to the layers that can host them.
     *
     * Previously every rule went to every layer. That is wrong twice over: a rule about `stt` means
     * nothing on a layer addressing only `loc`, and — because a pattern naming an absent dimension
     * matches nothing rather than complaining — it meant nothing *silently*. Combined with a rule
     * sequence whose base used to be read off its first entry, an inclusion-led contribution could
     * empty a layer it had no business touching.
     *
     * So each is offered to the layers carrying the dimensions it names, and one that no layer can
     * host is refused. That refusal is the point: it is a contributor naming a dimension nothing
     * has, which is a typo, and the alternative is a constraint that appears to be in force and is
     * not.
     */
    private function foldPlacement(LayeredSlotSpaceDefinition $definition): LayeredSlotSpaceDefinition
    {
        if ([] === $this->rules && [] === $this->confinements) {
            return $definition;
        }

        $layers = $definition->layers;

        /** @var array<non-empty-string, list<non-empty-string>> $namesByLayer */
        $namesByLayer = [];
        foreach ($layers as $name => $layer) {
            $namesByLayer[$name] = array_map(
                static fn ($dimension): string => $dimension->name,
                $layer->dimensions,
            );
        }

        foreach ($this->confinements as $confinement) {
            $hosts = array_keys(array_filter(
                $namesByLayer,
                static fn (array $names): bool => $confinement->appliesTo(...$names),
            ));

            if ([] === $hosts) {
                throw new ConfigurationException(
                    sprintf(
                        'Contributor "%s" confines "%s=%s" by "%s", but no layer carries both dimensions.',
                        $confinement->contributor,
                        $confinement->dimension,
                        $confinement->value,
                        $confinement->axis,
                    ),
                    'unhostable_confinement',
                    [
                        'contributor' => $confinement->contributor,
                        'dimension'   => $confinement->dimension,
                        'axis'        => $confinement->axis,
                    ],
                );
            }

            foreach ($hosts as $name) {
                $layers[$name] = $layers[$name]->withConfinement($confinement);
            }
        }

        foreach ($this->rules as $rule) {
            $named = is_array($rule->pattern) ? array_map(strval(...), array_keys($rule->pattern)) : [];

            foreach ($layers as $name => $layer) {
                // A positional (string) pattern carries no dimension names, so it cannot be routed
                // and goes everywhere — as before, and still the author's responsibility.
                if ([] !== $named && [] !== array_diff($named, $namesByLayer[$name])) {
                    continue;
                }
                $layers[$name] = $layer->withRule($rule);
            }
        }

        return LayeredSlotSpaceDefinition::define($layers);
    }

    /** Apply the deferred bindings now that every flow is registered. */
    private function foldBindings(LayeredSlotSpaceDefinition $definition): void
    {
        $registeredFlows = [];
        foreach ($definition->layers as $layer) {
            foreach ($layer->flows as $name => $_) {
                $registeredFlows[$name] = true;
            }
        }

        foreach ($this->pendingBindings as $pending) {
            $binding = $pending['binding'];
            if (!isset($registeredFlows[$binding->flow])) {
                throw new ConfigurationException(
                    sprintf(
                        'Contributor "%s" binds event "%s" to flow "%s", which no layer registers.',
                        $binding->contributorKey,
                        $binding->event,
                        $binding->flow,
                    ),
                    'unknown_bound_flow',
                    ['event' => $binding->event, 'flow' => $binding->flow, 'contributor' => $binding->contributorKey],
                );
            }

            $this->bindings->bind($binding);
        }
    }

    /**
     * Refuse a flow whose patterns cannot be satisfied by the space it is being added to.
     *
     * Two checks, both about failing where the culprit is still nameable:
     *
     * 1. **Every literal value must be registered or declared.** Left alone, an unregistered value
     *    surfaces at the first movement as "not valid for dimension" — on a merchant's site, months
     *    later, with nothing pointing at the add-on that wrote it.
     * 2. **No literal `loc` code.** A flow touches one axis and stays silent on the rest; a pattern
     *    naming a location is a flow that only works in one warehouse, and it is *always* either a
     *    parameter (`{location}`) or a diagonal hop that should have been two flows. Values in
     *    `loc` are merchant data, so a literal there is a contributor guessing at a merchant's
     *    warehouse names.
     *
     * `{param}` placeholders are skipped by both: they are resolved at execute time, and the engine
     * refuses one nothing answers.
     *
     * @psalm-param non-empty-string $layer
     * @psalm-param non-empty-string $contributor
     */
    private function assertPatternsAreSatisfiable(
        LayeredSlotSpaceDefinition $definition,
        string $layer,
        FlowDefinition $flow,
        string $contributor,
    ): void {
        // A dimension is value-checkable only when its full value set is visible here: inline
        // values in the definition, or a caller-supplied baseline for a DB-backed one. Contributed
        // values *add* to a checkable dimension's set but never make one checkable — otherwise the
        // first add-on to declare `qi` would make `atp` look unregistered.
        $checkable = [];
        $known = [];
        foreach ($definition->layers[$layer]->dimensions as $dimension) {
            if ([] !== $dimension->values) {
                $checkable[$dimension->name] = true;
                foreach ($dimension->values as $value) {
                    $known[$dimension->name][$value->code] = true;
                }
            }
        }
        foreach ($this->knownValues as $dimension => $codes) {
            $checkable[$dimension] = true;
            foreach ($codes as $code) {
                $known[$dimension][$code] = true;
            }
        }
        foreach ($this->requiredValues as $dimension => $byCode) {
            foreach ($byCode as $code => $_) {
                $known[$dimension][$code] = true;
            }
        }

        foreach ($flow->steps() as $step) {
            foreach ([$step->from, $step->to] as $pattern) {
                if (!is_array($pattern)) {
                    // A string pattern is positional (`sup.C.sd`) and carries no dimension names, so
                    // it cannot be checked per axis here. Contributors write the array form; the
                    // string form stays available and stays the author's responsibility.
                    continue;
                }

                foreach ($pattern as $dimension => $value) {
                    if (!is_string($dimension) || !is_string($value) || self::isParameter($value)) {
                        continue;
                    }

                    if ('loc' === $dimension) {
                        throw new ConfigurationException(
                            sprintf(
                                'Contributor "%s" names location "%s" literally in flow "%s" — use a {param} or split the hop.',
                                $contributor,
                                $value,
                                $flow->name(),
                            ),
                            'literal_loc_in_pattern',
                            ['contributor' => $contributor, 'flow' => $flow->name(), 'value' => $value],
                        );
                    }

                    if (isset($checkable[$dimension]) && !isset($known[$dimension][$value])) {
                        throw new ConfigurationException(
                            sprintf(
                                'Contributor "%s" uses unregistered value "%s" in dimension "%s" of flow "%s" — declare it with requireValues().',
                                $contributor,
                                $value,
                                $dimension,
                                $flow->name(),
                            ),
                            'unknown_slot_value',
                            [
                                'contributor' => $contributor,
                                'flow'        => $flow->name(),
                                'dimension'   => $dimension,
                                'value'       => $value,
                            ],
                        );
                    }
                }
            }
        }
    }

    /**
     * Whether two declarations of one code mean the same thing.
     *
     * Compared field by field rather than with `==`: object comparison would be rewritten to `===`
     * by the strict-comparison fixer (two equal declarations are never the same *instance*), and
     * `==` would also make a cosmetic difference — a label, a metadata note — read as a conflict
     * between two add-ons. What must match is what provisioning acts on.
     */
    private static function sameValue(DimensionValueDefinition $a, DimensionValueDefinition $b): bool
    {
        return $a->code === $b->code
            && $a->ownerKey === $b->ownerKey
            && $a->parentCode === $b->parentCode
            && $a->level === $b->level
            && $a->active === $b->active
            && $a->addressable === $b->addressable;
    }

    private static function isParameter(string $value): bool
    {
        return 1 === preg_match('/\{[-a-z_]*\}/i', $value);
    }
}
