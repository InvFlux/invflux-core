<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Schema;

use Nandan108\InvFlux\Exceptions\ConfigurationException;
use Nandan108\InvFlux\Flow\FlowDefinition;
use Nandan108\SlotFlow\Flow;
use Nandan108\SlotFlow\Rules\SlotRule;
use Nandan108\SlotFlow\SlotSpace;

/**
 * Describe one ordered declarative slot-space schema.
 *
 * @api
 */
final class SlotSpaceDefinition
{
    /**
     * @param list<DimensionDefinition> $dimensions
     * @param array<string, mixed>      $metadata
     */
    public static function define(string $name, array $dimensions, array $metadata = []): self
    {
        return new self($name, $dimensions, $metadata);
    }

    /**
     * Build one slot-space definition.
     *
     * @param list<DimensionDefinition>     $dimensions
     * @param array<string, mixed>          $metadata
     * @param array<string, FlowDefinition> $flows
     * @param list<SlotRule>                $rules
     * @param list<SlotConfinement>         $confinements
     */
    public function __construct(
        public readonly string $name,
        public readonly array $dimensions,
        public readonly array $metadata = [],
        public readonly array $flows = [],
        public readonly array $rules = [],
        public readonly array $confinements = [],
    ) {
        if ('' === $this->name) {
            throw new ConfigurationException('Slot space name must be a non-empty string.', 'empty_slotspace_name');
        }

        if ([] === $this->dimensions) {
            throw new ConfigurationException('Slot space must contain at least one dimension.', 'empty_slotspace_dimensions');
        }

        $seenNames = [];
        $seenPositions = [];
        foreach ($this->dimensions as $dimension) {
            if (isset($seenNames[$dimension->name])) {
                throw new ConfigurationException(
                    sprintf('Duplicate dimension name "%s" is not allowed.', $dimension->name),
                    'duplicate_dimension_name',
                    ['dimensionName' => $dimension->name],
                );
            }

            $seenNames[$dimension->name] = true;

            if (isset($seenPositions[$dimension->position])) {
                throw new ConfigurationException(
                    sprintf('Duplicate dimension position "%d" is not allowed.', $dimension->position),
                    'duplicate_dimension_position',
                    ['dimensionPosition' => $dimension->position],
                );
            }

            $seenPositions[$dimension->position] = true;
        }
    }

    /**
     * Return active dimensions in the normalized SlotFlow array shape.
     *
     * @return array<non-empty-string, list<non-empty-string>>
     */
    public function dimensionsAsArray(): array
    {
        /** @var array<non-empty-string, list<non-empty-string>> $dimensions */
        $dimensions = [];
        foreach ($this->activeDimensions() as $dimension) {
            $dimensionName = $this->requireNonEmptyString($dimension->name, 'dimension.name');
            $dimensions[$dimensionName] = $dimension->valuesAsList();
        }

        return $dimensions;
    }

    /**
     * Return the active dimensions sorted by their declared position.
     *
     * @return list<DimensionDefinition>
     */
    public function activeDimensions(): array
    {
        $activeDimensions = array_values(array_filter(
            $this->dimensions,
            static fn (DimensionDefinition $dimension): bool => $dimension->active,
        ));

        usort(
            $activeDimensions,
            static fn (DimensionDefinition $left, DimensionDefinition $right): int => $left->position <=> $right->position,
        );

        return $activeDimensions;
    }

    /** Return one declared dimension by name, or null when it is not present. */
    public function dimensionByName(string $name): ?DimensionDefinition
    {
        foreach ($this->dimensions as $dimension) {
            if ($dimension->name === $name) {
                return $dimension;
            }
        }

        return null;
    }

    /**
     * Return a new definition with the given flow added.
     *
     * @throws ConfigurationException when a flow with the same name already exists
     */
    public function withFlow(FlowDefinition $flow): self
    {
        if (isset($this->flows[$flow->name()])) {
            throw new ConfigurationException(
                sprintf('Flow "%s" is already defined on slot space "%s".', $flow->name(), $this->name),
                'duplicate_flow',
                ['flow' => $flow->name(), 'slotSpace' => $this->name],
            );
        }

        return new self($this->name, $this->dimensions, $this->metadata, [...$this->flows, $flow->name() => $flow], $this->rules, $this->confinements);
    }

    /**
     * Return a new definition with the given placement constraints appended.
     */
    public function withConfinement(SlotConfinement ...$confinements): self
    {
        return new self(
            $this->name,
            $this->dimensions,
            $this->metadata,
            $this->flows,
            $this->rules,
            array_values([...$this->confinements, ...$confinements]),
        );
    }

    /**
     * Return a new definition with the given slot rules appended.
     *
     * Rules are applied before flow compilation; a deny rule prunes matching slots from the
     * compiled SlotSpace so those combinations never appear in invflux_slotspace rows.
     */
    public function withRule(SlotRule ...$rules): self
    {
        return new self($this->name, $this->dimensions, $this->metadata, $this->flows, array_values([...$this->rules, ...$rules]), $this->confinements);
    }

    /**
     * Return a JSON-serializable snapshot of this slot-space definition.
     *
     * @return array<string, mixed>
     */
    public function toDefinition(): array
    {
        return [
            'name'       => $this->name,
            'dimensions' => array_map(
                static function (DimensionDefinition $dim): array {
                    if ($dim->isSharedRef) {
                        return [
                            'name'          => $dim->name,
                            'position'      => $dim->position,
                            'active'        => $dim->active,
                            'sharedRef'     => true,
                            'valueSelector' => $dim->valueSelector?->toArray() ?? [],
                        ];
                    }

                    return [
                        'name'        => $dim->name,
                        'position'    => $dim->position,
                        'default'     => $dim->defaultValue,
                        'kind'        => $dim->kind->value,
                        'collapse'    => $dim->collapseBehavior->value,
                        'collapse_to' => $dim->collapseTargetValue,
                        'active'      => $dim->active,
                        'values'      => array_map(
                            static fn (DimensionValueDefinition $v): array => array_filter([
                                'code'           => $v->code,
                                'name'           => $v->name,
                                'owner'          => $v->ownerKey,
                                'active'         => $v->active,
                                'removal_target' => $v->removalTargetCode,
                                'parent_code'    => $v->parentCode,
                                'addressable'    => $v->addressable ? null : false,
                                'level'          => $v->level,
                            ], static fn (mixed $x): bool => null !== $x),
                            $dim->values,
                        ),
                    ];
                },
                $this->dimensions,
            ),
            'flows'    => array_map(
                static fn (FlowDefinition $flow): array => $flow->toDefinition(),
                $this->flows,
            ),
            'rules'    => array_map(
                static fn (SlotRule $rule): array => [
                    'allow'   => $rule->allow,
                    'pattern' => $rule->pattern,
                ],
                $this->rules,
            ),
            'confinements' => array_map(
                static fn (SlotConfinement $c): array => [
                    'dimension'   => $c->dimension,
                    'value'       => $c->value,
                    'axis'        => $c->axis,
                    'allowed'     => $c->allowed,
                    'contributor' => $c->contributor,
                ],
                $this->confinements,
            ),
            'metadata' => $this->metadata,
        ];
    }

    /** Convert this declarative definition into a SlotFlow slot space, compiling all registered flows. */
    public function toSlotSpace(): SlotSpace
    {
        $space = SlotSpace::define($this->dimensionsAsArray());

        if ([] !== $this->rules) {
            $space = $space->slotRules($this->rules);
        }

        // After the rules, deliberately. A rule sequence shapes the space; a confinement states
        // something that must hold of the result, so it has the last word — and being an
        // intersection it cannot re-admit anything a rule removed.
        foreach ($this->confinements as $confinement) {
            $space = $space->confine(
                $confinement->dimension,
                $confinement->value,
                $confinement->axis,
                $confinement->allowed,
            );
        }

        foreach ($this->flows as $flowDef) {
            $space->flow($flowDef->name(), static fn (Flow $f) => $flowDef->compile($f));
        }

        return $space;
    }

    /** @return non-empty-string */
    private function requireNonEmptyString(string $value, string $label): string
    {
        if ('' === $value) {
            throw new ConfigurationException(sprintf('%s must be a non-empty string.', $label), 'empty_string_value');
        }

        return $value;
    }
}
