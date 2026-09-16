<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Flow\Constraints;

use Nandan108\SlotFlow\Contracts\QttyConstraintPolicyInterface;
use Nandan108\SlotFlow\Contracts\SerializablePolicy;
use Nandan108\SlotFlow\MovementEdge;
use Nandan108\SlotFlow\Runtime\FlowContext;

/**
 * Keep at least N units in the slots matching a given pattern.
 *
 * Applied as a per-edge quantity constraint: when the source slot matches
 * the protected pattern, at most max(0, current − min) units may move
 * through that edge.
 *
 * @api
 */
final class MinSlotTotal implements QttyConstraintPolicyInterface, SerializablePolicy
{
    /**
     * @param non-empty-string $pattern
     */
    public function __construct(
        private readonly string $pattern,
        private readonly int | float $min,
    ) {
    }

    /** @param non-empty-string $pattern */
    public static function define(string $pattern, int | float $min): self
    {
        return new self($pattern, $min);
    }

    #[\Override]
    public function constraint(MovementEdge $edge, FlowContext $ctx): int | float
    {
        /** @psalm-suppress ArgumentTypeCoercion */
        $matchingSlots = $ctx->space->matchPattern($this->pattern);

        foreach ($matchingSlots as $slot) {
            if ($slot->key === $edge->from->key) {
                /** @psalm-suppress InvalidOperand */
                return max(0, $ctx->inventory->get($edge->from) - $this->min);
            }
        }

        return PHP_INT_MAX;
    }

    #[\Override]
    public function toDefinition(): array
    {
        return ['type' => 'MinSlotTotal', 'pattern' => $this->pattern, 'min' => $this->min];
    }

    #[\Override]
    public static function fromDefinition(array $data): static
    {
        /** @psalm-var int|float $min */
        $min = $data['min'];

        /** @psalm-suppress ArgumentTypeCoercion */
        return new static((string) $data['pattern'], $min);
    }
}
