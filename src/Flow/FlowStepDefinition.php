<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Flow;

use Nandan108\SlotFlow\Contracts\AllocationPolicyInterface;
use Nandan108\SlotFlow\Contracts\EdgeOrderingPolicyInterface;
use Nandan108\SlotFlow\Contracts\QttyConstraintPolicyInterface;

/**
 * @internal
 *
 * @psalm-import-type TSlotPattern from \Nandan108\SlotFlow\SlotSpace
 */
final class FlowStepDefinition
{
    /**
     * @var list<EdgeOrderingPolicyInterface|PolicyDescriptor>
     */
    public array $ordering = [];

    /**
     * @var list<QttyConstraintPolicyInterface|PolicyDescriptor|callable>
     */
    public array $constraints = [];

    /**
     * @var list<AllocationPolicyInterface|PolicyDescriptor|callable>
     */
    public array $allocations = [];

    /**
     * @psalm-param TSlotPattern $from
     * @psalm-param TSlotPattern $to
     */
    public function __construct(
        public readonly string | array | null $from,
        public readonly string | array | null $to,
    ) {
    }
}
