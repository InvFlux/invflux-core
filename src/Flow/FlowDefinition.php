<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Flow;

use Nandan108\InvFlux\Flow\Constraints\MaxFlowQuantity;
use Nandan108\InvFlux\Flow\Constraints\MaxSlotTotal;
use Nandan108\InvFlux\Flow\Constraints\MinSlotTotal;
use Nandan108\SlotFlow\Contracts\AllocationPolicyInterface;
use Nandan108\SlotFlow\Contracts\EdgeOrderingPolicyInterface;
use Nandan108\SlotFlow\Contracts\QttyConstraintPolicyInterface;
use Nandan108\SlotFlow\Contracts\SerializablePolicy;
use Nandan108\SlotFlow\Flow;

/**
 * Declarative, serializable description of a named flow.
 *
 * Compiles to a SlotFlow Flow at runtime via compile() / toFlow().
 * Serializes to a plain array via toDefinition() for config ledger snapshots.
 *
 * Policies that do not implement SerializablePolicy (or are callables) degrade
 * gracefully: the flow is marked serializable=false in toDefinition().
 *
 * @psalm-import-type TSlotPattern from \Nandan108\SlotFlow\SlotSpace
 *
 * @api
 */
final class FlowDefinition
{
    /** @psalm-var non-empty-string */
    private string $flowName;

    /** @var list<FlowStepDefinition> */
    private array $steps = [];

    private bool $serializable = true;

    private function __construct(string $name)
    {
        if ('' === $name) {
            throw new \InvalidArgumentException('Flow name must not be empty.');
        }

        $this->flowName = $name;
    }

    public static function define(string $name): self
    {
        return new self($name);
    }

    /** @return non-empty-string */
    public function name(): string
    {
        return $this->flowName;
    }

    public function isSerializable(): bool
    {
        return $this->serializable;
    }

    /**
     * The declared steps, for a caller that must inspect the patterns.
     *
     * Read-only in intent; `FlowStepDefinition` is `@internal` and its policy arrays are mutable,
     * so treat what comes back as a view. `toDefinition()` is the wrong tool for inspection: it is
     * lossy by design — a non-serializable policy (a raw callable constraint, which
     * {@see constraint()} accepts) is dropped from its output, so a validator walking it would
     * silently pass a flow it never really examined.
     *
     * The caller with a real need is the slot-space assembler, which checks every contributed
     * flow's patterns against the registered dimension values before anything is executed —
     * failing at assembly, where the contributor can be named, rather than at the first movement.
     *
     * @return list<FlowStepDefinition>
     */
    public function steps(): array
    {
        return $this->steps;
    }

    /**
     * Add a movement step from one slot pattern to another.
     * Policies added after this call (orderBy, constraint, allocate) apply to this step.
     *
     * @psalm-param TSlotPattern $from
     * @psalm-param TSlotPattern $to
     */
    public function move(string | array | null $from, string | array | null $to): self
    {
        $this->steps[] = new FlowStepDefinition($from, $to);

        return $this;
    }

    /**
     * Add a creation step that creates quantity in the specified slot pattern.
     * Equivalent to move(null, $to) but semantically clearer for import flows.
     *
     * @psalm-param TSlotPattern $to
     */
    public function create(string | array | null $to): self
    {
        return $this->move(null, $to);
    }

    /**
     * Add a destruction step that removes quantity from the specified slot pattern.
     * Equivalent to move($from, null) but semantically clearer for export flows.
     *
     * @psalm-param TSlotPattern $from
     */
    public function destroy(string | array | null $from): self
    {
        return $this->move($from, null);
    }

    /**
     * Add an ordering policy to the last step.
     */
    public function orderBy(EdgeOrderingPolicyInterface | PolicyDescriptor $policy): self
    {
        if (!($policy instanceof SerializablePolicy) && !($policy instanceof PolicyDescriptor)) {
            $this->serializable = false;
        }

        $this->lastStep()->ordering[] = $policy;

        return $this;
    }

    /**
     * Add a quantity constraint to the last step.
     * Callable constraints mark the flow non-serializable.
     *
     * @param QttyConstraintPolicyInterface|PolicyDescriptor|callable(mixed, mixed): (int|float) $constraint
     */
    public function constraint(QttyConstraintPolicyInterface | PolicyDescriptor | callable $constraint): self
    {
        if (!($constraint instanceof QttyConstraintPolicyInterface) && !($constraint instanceof PolicyDescriptor)) {
            $this->serializable = false;
        } elseif ($constraint instanceof QttyConstraintPolicyInterface && !($constraint instanceof SerializablePolicy)) {
            $this->serializable = false;
        }

        $this->lastStep()->constraints[] = $constraint;

        return $this;
    }

    /**
     * Add an allocation policy to the last step.
     * Callable allocation policies mark the flow non-serializable.
     *
     * @param AllocationPolicyInterface|PolicyDescriptor|callable(mixed): mixed $policy
     */
    public function allocate(AllocationPolicyInterface | PolicyDescriptor | callable $policy): self
    {
        if (!($policy instanceof AllocationPolicyInterface) && !($policy instanceof PolicyDescriptor)) {
            $this->serializable = false;
        } elseif ($policy instanceof AllocationPolicyInterface && !($policy instanceof SerializablePolicy)) {
            $this->serializable = false;
        }

        $this->lastStep()->allocations[] = $policy;

        return $this;
    }

    /**
     * Compile this definition into an existing SlotFlow Flow instance.
     */
    public function compile(Flow $flow): void
    {
        foreach ($this->steps as $stepDef) {
            $stepBuilder = $flow->move($stepDef->from, $stepDef->to);

            foreach ($stepDef->ordering as $policy) {
                /** @psalm-suppress MixedArgumentTypeCoercion */
                $stepBuilder->orderBy(
                    $policy instanceof PolicyDescriptor ? $policy->policy : $policy,
                );
            }

            foreach ($stepDef->constraints as $constraint) {
                /** @psalm-suppress MixedArgumentTypeCoercion */
                $stepBuilder->constraint(
                    $constraint instanceof PolicyDescriptor ? $constraint->policy : $constraint,
                );
            }

            foreach ($stepDef->allocations as $policy) {
                /** @psalm-suppress MixedArgumentTypeCoercion */
                $stepBuilder->allocate(
                    $policy instanceof PolicyDescriptor ? $policy->policy : $policy,
                );
            }
        }
    }

    /**
     * Compile this definition into a new SlotFlow Flow.
     */
    public function toFlow(): Flow
    {
        $flow = new Flow($this->flowName);
        $this->compile($flow);

        return $flow;
    }

    /**
     * Return a JSON-serializable representation of this flow definition.
     *
     * When serializable is false, policy arrays may be incomplete or empty.
     *
     * @return array<string, mixed>
     */
    public function toDefinition(): array
    {
        return [
            'name'         => $this->flowName,
            'serializable' => $this->serializable,
            'steps'        => array_map(
                static fn (FlowStepDefinition $step): array => [
                    'from'     => $step->from,
                    'to'       => $step->to,
                    'ordering' => array_values(array_filter(array_map(
                        static fn (EdgeOrderingPolicyInterface | PolicyDescriptor $p): ?array => $p instanceof SerializablePolicy
                            ? $p->toDefinition()
                            : ($p instanceof PolicyDescriptor ? $p->definition : null),
                        $step->ordering,
                    ))),
                    'constraints' => array_values(array_filter(array_map(
                        static function (mixed $p): ?array {
                            if ($p instanceof SerializablePolicy) {
                                return $p->toDefinition();
                            }
                            if ($p instanceof PolicyDescriptor) {
                                return $p->definition;
                            }

                            return null;
                        },
                        $step->constraints,
                    ))),
                    'allocations' => array_values(array_filter(array_map(
                        static function (mixed $p): ?array {
                            if ($p instanceof SerializablePolicy) {
                                return $p->toDefinition();
                            }
                            if ($p instanceof PolicyDescriptor) {
                                return $p->definition;
                            }

                            return null;
                        },
                        $step->allocations,
                    ))),
                ],
                $this->steps,
            ),
        ];
    }

    /**
     * Reconstruct a FlowDefinition from a previously serialized definition.
     *
     * Only works for flows that were serializable=true.
     *
     * @param array<string, mixed> $data
     */
    public static function fromDefinition(array $data): self
    {
        $def = new self((string) $data['name']);

        /** @var array<int, array<string, mixed>> $steps */
        $steps = $data['steps'] ?? [];

        foreach ($steps as $stepData) {
            /** @psalm-var mixed $from */
            $from = $stepData['from'] ?? null;
            /** @psalm-var mixed $to */
            $to = $stepData['to'] ?? null;

            /** @psalm-suppress MixedArgument */
            $def->move($from, $to);

            /** @var list<array<string, mixed>> $ordering */
            $ordering = $stepData['ordering'] ?? [];
            foreach ($ordering as $policyData) {
                $policy = self::deserializePolicy($policyData);
                if ($policy instanceof EdgeOrderingPolicyInterface) {
                    $def->orderBy($policy);
                }
            }

            /** @var list<array<string, mixed>> $constraints */
            $constraints = $stepData['constraints'] ?? [];
            foreach ($constraints as $policyData) {
                $policy = self::deserializePolicy($policyData);
                if ($policy instanceof QttyConstraintPolicyInterface) {
                    $def->constraint($policy);
                }
            }
        }

        return $def;
    }

    /**
     * Deserialize a policy from its definition array.
     *
     * @param array<string, mixed> $data
     */
    private static function deserializePolicy(array $data): SerializablePolicy
    {
        $type = isset($data['type']) ? (string) $data['type'] : '';

        /** @var class-string<SerializablePolicy> $class */
        $class = match ($type) {
            'DimensionPriority' => \Nandan108\SlotFlow\Policies\DimensionPriority::class,
            'MinSlotTotal'      => MinSlotTotal::class,
            'MaxSlotTotal'      => MaxSlotTotal::class,
            'MaxFlowQuantity'   => MaxFlowQuantity::class,
            default             => throw new \InvalidArgumentException(sprintf('Unknown policy type "%s".', $type)),
        };

        return $class::fromDefinition($data);
    }

    private function lastStep(): FlowStepDefinition
    {
        if ([] === $this->steps) {
            throw new \LogicException('No step defined yet — call move() before adding policies.');
        }

        return $this->steps[count($this->steps) - 1];
    }
}
