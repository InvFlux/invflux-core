<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Layer;

use Nandan108\InvFlux\Exceptions\ConfigurationException;
use Nandan108\SlotFlow\QuantityState;

/**
 * A named map of QuantityState instances, one per inventory layer.
 *
 * Loaded from DB by the storage adapter before executing a boundary flow.
 *
 * @api
 */
final class LayeredQuantityState
{
    /**
     * @param array<string, QuantityState> $states
     */
    public function __construct(
        private readonly array $states,
    ) {
    }

    /**
     * @param array<string, QuantityState> $states
     */
    public static function define(array $states): self
    {
        return new self($states);
    }

    /**
     * Return the QuantityState for a given layer name.
     *
     * @throws ConfigurationException when the layer does not exist
     */
    public function forLayer(string $layer): QuantityState
    {
        if (!isset($this->states[$layer])) {
            throw new ConfigurationException(
                sprintf('No quantity state found for layer "%s".', $layer),
                'unknown_layer',
                ['layer' => $layer],
            );
        }

        return $this->states[$layer];
    }

    /** @return array<string, QuantityState> */
    public function all(): array
    {
        return $this->states;
    }
}
