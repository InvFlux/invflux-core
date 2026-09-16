<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Schema\Extension;

use Nandan108\InvFlux\Registry\BaseMovementType;

/**
 * One answer to "when this domain event happens, which flow runs, under which movement type".
 *
 * The binding — not the flow — is the override surface. `clear_ctd` never changes meaning
 * ("committed stock leaves the system"); what an add-on changes is *which* flow the *dispatched*
 * event executes. That collapses every override-conflict question into one visible collision: two
 * contributors rebinding the same event.
 *
 * @api
 */
final class EventBinding
{
    /**
     * @param non-empty-string      $event                domain event id, e.g. `order.dispatched`
     * @param non-empty-string      $flow                 name of a registered flow (or boundary flow)
     * @param non-empty-string      $movementTypeCode     movement type the executed flow is recorded under —
     *                                                    side effects key on this, so rebinding an event
     *                                                    relocates its effects with it
     * @param non-empty-string      $contributorKey       who bound it, for diagnostics and conflict messages
     * @param non-empty-string|null $expect               the contributor this binding believes currently owns
     *                                                    the event; a mismatch warns rather than throws —
     *                                                    it catches a third party silently rebinding a rebind
     * @param non-empty-string      $movementTypeOwnerKey the half of the movement type's identity the code
     *                                                    alone does not carry — a type is `(owner_key, code)`,
     *                                                    so a binding naming only the code can only ever reach
     *                                                    core's own set. An add-on rebinding an event to a
     *                                                    movement type *it* registered names its key here.
     *                                                    Defaults to core's, which is what every base binding
     *                                                    means
     */
    public function __construct(
        public readonly string $event,
        public readonly string $flow,
        public readonly string $movementTypeCode,
        public readonly string $contributorKey,
        public readonly int $priority,
        public readonly ?string $expect = null,
        public readonly string $movementTypeOwnerKey = BaseMovementType::OWNER_KEY,
    ) {
    }

    /** @return array<string, mixed> */
    public function toDefinition(): array
    {
        return [
            'event'                => $this->event,
            'flow'                 => $this->flow,
            'movementTypeOwnerKey' => $this->movementTypeOwnerKey,
            'movementTypeCode'     => $this->movementTypeCode,
            'contributor'          => $this->contributorKey,
            'priority'             => $this->priority,
        ];
    }
}
