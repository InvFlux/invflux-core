<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Flow\Constraints;

use Nandan108\SlotFlow\Contracts\QttyConstraintPolicyInterface;
use Nandan108\SlotFlow\Contracts\SerializablePolicy;
use Nandan108\SlotFlow\MovementEdge;
use Nandan108\SlotFlow\Runtime\FlowContext;

/**
 * Cap the total quantity moved in one flow execution.
 *
 * Applied as a per-edge quantity constraint. For single-edge steps this
 * correctly caps the total; for multi-edge steps each edge is individually
 * limited (effectively a soft cap on the total).
 *
 * @api
 */
final class MaxFlowQuantity implements QttyConstraintPolicyInterface, SerializablePolicy
{
    public function __construct(
        private readonly int | float $max,
    ) {
    }

    public static function define(int | float $max): self
    {
        return new self($max);
    }

    #[\Override]
    public function constraint(MovementEdge $edge, FlowContext $ctx): int | float
    {
        return $this->max;
    }

    #[\Override]
    public function toDefinition(): array
    {
        return ['type' => 'MaxFlowQuantity', 'max' => $this->max];
    }

    #[\Override]
    public static function fromDefinition(array $data): static
    {
        /** @psalm-var int|float $max */
        $max = $data['max'];

        return new static($max);
    }
}
