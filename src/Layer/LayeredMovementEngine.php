<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Layer;

use Nandan108\InvFlux\Exceptions\ConfigurationException;
use Nandan108\SlotFlow\Exceptions\SlotFlowInvalidArgumentException;
use Nandan108\SlotFlow\MovementEngine;
use Nandan108\SlotFlow\SlotSpace;

/**
 * Executes a boundary flow across all inventory layers simultaneously.
 *
 * Each layer is executed independently via SlotFlow's MovementEngine. All
 * layers are always executed before inspecting results — a failure on one
 * layer never prevents the others from being attempted, so the caller always
 * receives a complete per-layer diagnostic.
 *
 * Since SlotFlow never mutates state (it only produces deltas), all layers
 * can be evaluated before anything is persisted. A partial failure costs
 * nothing and is safe to discard.
 *
 * For intra-layer flows (reserve, release, mark-sold, bin-transfer) use
 * SlotFlow's MovementEngine directly against the relevant layer's SlotSpace.
 *
 * @api
 */
final class LayeredMovementEngine
{
    public function __construct(
        private readonly MovementEngine $engine = new MovementEngine(),
    ) {
    }

    /**
     * Execute a boundary flow for one subject across all layers.
     *
     * $spaces must be pre-compiled SlotSpace instances with flows already
     * registered on them (via SlotSpace::flow()). The BoundaryFlow must define
     * a flow entry for every key present in $spaces; a mismatch throws
     * ConfigurationException.
     *
     * @param array<string, SlotSpace> $spaces  pre-compiled, flow-registered slot spaces keyed by layer name
     * @param mixed                    $subject passed through to SlotFlow's solver
     *
     * @throws ConfigurationException when $spaces is empty or BoundaryFlow is missing a layer entry
     */
    public function execute(
        LayeredQuantityState $state,
        array $spaces,
        BoundaryFlow $flow,
        int | float $quantity,
        mixed $subject = null,
    ): LayeredMovementResult {
        if ([] === $spaces) {
            throw new ConfigurationException(
                'No slot spaces provided to LayeredMovementEngine.',
                'empty_layered_slotspace',
            );
        }

        $results = [];

        foreach ($spaces as $layer => $layerSpace) {
            $flowId = $flow->forLayer($layer);
            $layerState = $state->forLayer($layer);

            try {
                $results[$layer] = $this->engine->execute(
                    $layerState,
                    $layerSpace,
                    $flowId,
                    $quantity,
                    $subject,
                );
            } catch (SlotFlowInvalidArgumentException $e) {
                throw new ConfigurationException(
                    sprintf('Layer "%s": %s', $layer, $e->getMessage()),
                    'invalid_layer_flow',
                    ['layer' => $layer],
                    $e,
                );
            }
        }

        $ok = array_reduce(
            $results,
            static fn (bool $carry, \Nandan108\SlotFlow\MovementResult $r): bool => $carry && $r->isComplete(),
            true,
        );

        return new LayeredMovementResult($ok, $results);
    }
}
