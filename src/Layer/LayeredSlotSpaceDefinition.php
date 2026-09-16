<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Layer;

use Nandan108\InvFlux\Exceptions\ConfigurationException;
use Nandan108\InvFlux\Flow\FlowDefinition;
use Nandan108\InvFlux\Schema\SlotSpaceDefinition;
use Nandan108\SlotFlow\SlotSpace;

/**
 * A named map of SlotSpaceDefinition instances, one per inventory layer.
 *
 * Pass to LayeredMovementEngine and to the storage adapter bootstrap so that
 * all layer-aware components share the same declarative schema.
 *
 * @api
 */
final class LayeredSlotSpaceDefinition
{
    /**
     * @param array<string, SlotSpaceDefinition> $layers
     */
    public function __construct(
        public readonly array $layers,
    ) {
        if ([] === $layers) {
            throw new ConfigurationException(
                'A layered slot space definition must contain at least one layer.',
                'empty_layered_slotspace',
            );
        }
    }

    /**
     * @param array<string, SlotSpaceDefinition> $layers
     */
    public static function define(array $layers): self
    {
        return new self($layers);
    }

    /**
     * Compile each layer to a SlotFlow SlotSpace.
     *
     * @return array<string, SlotSpace>
     */
    public function toSlotSpaces(): array
    {
        $spaces = [];
        foreach ($this->layers as $name => $definition) {
            $spaces[$name] = $definition->toSlotSpace();
        }

        return $spaces;
    }

    /**
     * Return a new definition with the given flow registered on each named layer.
     *
     * Each entry in $layerFlows maps a layer name to the FlowDefinition for that layer.
     * All named layers must already exist in this definition. The flow name is taken
     * from each FlowDefinition instance; use the same name across all layers to make
     * BoundaryFlow::define() trivial.
     *
     * @param array<string, FlowDefinition> $layerFlows layer name => flow definition
     *
     * @throws ConfigurationException when a key references an undefined layer
     */
    public function withBoundaryFlow(array $layerFlows): self
    {
        $layers = $this->layers;

        foreach ($layerFlows as $layerName => $flowDef) {
            if (!isset($layers[$layerName])) {
                throw new ConfigurationException(
                    sprintf('No layer "%s" defined in this layered slot space definition.', $layerName),
                    'unknown_layer',
                    ['layer' => $layerName],
                );
            }

            $layers[$layerName] = $layers[$layerName]->withFlow($flowDef);
        }

        return new self($layers);
    }

    /** Return the names of all defined layers. */
    public function layerNames(): array
    {
        return array_keys($this->layers);
    }

    /**
     * Return a JSON-serializable snapshot of this layered slot-space definition.
     *
     * @return array<string, mixed>
     */
    public function toDefinition(): array
    {
        return [
            'layers' => array_map(
                static fn (SlotSpaceDefinition $layer): array => $layer->toDefinition(),
                $this->layers,
            ),
        ];
    }
}
