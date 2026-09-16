<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Layer;

use Nandan108\SlotFlow\MovementResult;

/**
 * Immutable result of a boundary flow execution across all layers.
 *
 * When ok is false the per-layer results show exactly which layers succeeded
 * and which had remainder. The caller can derive the safe retry quantity:
 *
 *   $retryQty = min(array_map(fn($r) => $r->remaining === 0
 *       ? PHP_INT_MAX
 *       : ($requestedQty - $r->remaining),
 *       $result->byLayer()
 *   ));
 *
 * Or more simply — use LayeredMovementEngine::maxFulfillable() on the result.
 *
 * @api
 */
final class LayeredMovementResult
{
    /**
     * @param array<string, MovementResult> $byLayer
     */
    public function __construct(
        public readonly bool $ok,
        private readonly array $byLayer,
    ) {
    }

    /**
     * Return per-layer MovementResult instances keyed by layer name.
     *
     * @return array<string, MovementResult>
     */
    public function byLayer(): array
    {
        return $this->byLayer;
    }

    /**
     * Return the MovementResult for one layer.
     */
    public function forLayer(string $layer): MovementResult
    {
        return $this->byLayer[$layer] ?? throw new \InvalidArgumentException(
            sprintf('No result found for layer "%s".', $layer),
        );
    }

    /**
     * Return the maximum quantity that all layers could fulfill simultaneously.
     *
     * Useful for computing the safe retry quantity after a partial failure:
     * call this on a failed result, then re-execute with the returned quantity.
     *
     * @param int|float $requested the quantity originally requested
     */
    public function maxFulfillable(int | float $requested): int | float
    {
        $min = $requested;
        foreach ($this->byLayer as $result) {
            /** @psalm-suppress InvalidOperand */
            $fulfilled = $requested - $result->remaining;
            if ($fulfilled < $min) {
                $min = $fulfilled;
            }
        }

        return $min;
    }
}
