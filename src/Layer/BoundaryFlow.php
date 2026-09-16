<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Layer;

use Nandan108\InvFlux\Exceptions\ConfigurationException;

/**
 * A named map of flow identifiers, one per inventory layer.
 *
 * Each string names a flow registered on the corresponding layer's SlotSpace.
 * The boundary quantity is the same for all layers; only the internal
 * distribution within each layer differs.
 *
 * @api
 */
final class BoundaryFlow
{
    /**
     * @param array<string, string> $flows layer name => flow identifier
     */
    public function __construct(
        private readonly array $flows,
    ) {
        if ([] === $flows) {
            throw new ConfigurationException(
                'A boundary flow must define at least one layer.',
                'empty_boundary_flow',
            );
        }
    }

    /**
     * @param array<string, string> $flows layer name => flow identifier
     */
    public static function define(array $flows): self
    {
        return new self($flows);
    }

    /**
     * Return the flow identifier for a given layer.
     *
     * @throws ConfigurationException when the layer is not defined in this boundary flow
     */
    public function forLayer(string $layer): string
    {
        if (!isset($this->flows[$layer])) {
            throw new ConfigurationException(
                sprintf('No flow defined for layer "%s" in this boundary flow.', $layer),
                'unknown_boundary_flow_layer',
                ['layer' => $layer],
            );
        }

        return $this->flows[$layer];
    }

    /** @return array<string, string> */
    public function all(): array
    {
        return $this->flows;
    }

    /**
     * Return the names of all layers defined in this boundary flow.
     *
     * @return list<string>
     */
    public function layerNames(): array
    {
        return array_keys($this->flows);
    }
}
