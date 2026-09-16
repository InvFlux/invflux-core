<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Flow\Constraints;

use Nandan108\SlotFlow\Contracts\QttyConstraintPolicyInterface;
use Nandan108\SlotFlow\Contracts\SerializablePolicy;
use Nandan108\SlotFlow\MovementEdge;
use Nandan108\SlotFlow\Runtime\FlowContext;

/**
 * Cap the quantity in the slots matching a given pattern.
 *
 * Applied as a per-edge quantity constraint: when the destination slot
 * matches the capped pattern, at most max(0, max − current) units may
 * flow into it through that edge.
 *
 * @api
 */
final class MaxSlotTotal implements QttyConstraintPolicyInterface, SerializablePolicy
{
    /**
     * @param non-empty-string $pattern
     */
    public function __construct(
        private readonly string $pattern,
        private readonly int | float $max,
    ) {
    }

    /** @param non-empty-string $pattern */
    public static function define(string $pattern, int | float $max): self
    {
        return new self($pattern, $max);
    }

    #[\Override]
    public function constraint(MovementEdge $edge, FlowContext $ctx): int | float
    {
        /** @psalm-suppress ArgumentTypeCoercion */
        $matchingSlots = $ctx->space->matchPattern($this->pattern);

        foreach ($matchingSlots as $slot) {
            if ($slot->key === $edge->to->key) {
                /** @psalm-suppress InvalidOperand */
                return max(0, $this->max - $ctx->inventory->get($edge->to));
            }
        }

        return PHP_INT_MAX;
    }

    #[\Override]
    public function toDefinition(): array
    {
        return ['type' => 'MaxSlotTotal', 'pattern' => $this->pattern, 'max' => $this->max];
    }

    #[\Override]
    public static function fromDefinition(array $data): static
    {
        /** @psalm-var int|float $max */
        $max = $data['max'];

        /** @psalm-suppress ArgumentTypeCoercion */
        return new static((string) $data['pattern'], $max);
    }
}
