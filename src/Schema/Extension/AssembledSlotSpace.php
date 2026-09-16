<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Schema\Extension;

use Nandan108\InvFlux\Layer\LayeredSlotSpaceDefinition;
use Nandan108\InvFlux\Registry\MovementTypeDefinition;
use Nandan108\InvFlux\Schema\DimensionValueDefinition;

/**
 * Everything one assembly pass produced, as one value.
 *
 * Four things come out of assembling the slot space, and bootstrap needs all four — the definition
 * to run flows against, the values to provision, the movement types to register, the bindings to
 * route events by. Returning them together rather than as four accessors is the point: they are one
 * consistent snapshot, and four calls could disagree if a contributor ran in between.
 *
 * @api
 */
final class AssembledSlotSpace
{
    /**
     * @param array<non-empty-string, list<DimensionValueDefinition>> $requiredValues  dimension => values to
     *                                                                                 register (additive, idempotent)
     * @param array<non-empty-string, list<MovementTypeDefinition>>   $movementTypes   ownerKey => types
     * @param list<non-empty-string>                                  $contributorKeys in fold order
     */
    public function __construct(
        public readonly LayeredSlotSpaceDefinition $definition,
        public readonly EventBindingRegistry $bindings,
        public readonly array $requiredValues,
        public readonly array $movementTypes,
        public readonly array $contributorKeys = [],
    ) {
    }

    /**
     * A stable fingerprint of everything that has to be *provisioned* — the contributed values and
     * movement types.
     *
     * This is the provisioning gate's marker. The version constant it replaces is bumped by a core
     * release, but the event that makes provisioning stale here is a **merchant activating an
     * add-on**, weeks after install, which no constant can anticipate: the marker still matches,
     * provisioning is skipped, and the add-on's values are never registered. The symptom arrives
     * months later as "unknown slot key" at a first dispatch.
     *
     * Canonicalized before hashing — `requiredValues()` and `movementTypes()` sort, and the version
     * is folded in explicitly. An unstable hash re-provisions on *every* request, which is worse
     * than never re-provisioning.
     *
     * @psalm-param non-empty-string $provisionVersion core's own seed-data counter
     *
     * @return non-empty-string
     */
    public function provisioningFingerprint(string $provisionVersion): string
    {
        $material = [
            'version'       => $provisionVersion,
            // Only the fields that decide whether provisioning has work to do. A label or a
            // metadata tweak is not a reason to re-register values on every site.
            'values'        => array_map(
                static fn (array $values): array => array_map(
                    static fn (DimensionValueDefinition $value): array => [
                        $value->code,
                        $value->ownerKey,
                        $value->parentCode,
                        $value->level,
                        $value->active,
                        $value->addressable,
                    ],
                    $values,
                ),
                $this->requiredValues,
            ),
            'movementTypes' => array_map(
                static fn (array $types): array => array_map(
                    static fn (MovementTypeDefinition $type): array => [$type->code, $type->active],
                    $types,
                ),
                $this->movementTypes,
            ),
        ];

        return $provisionVersion.'|'.substr(hash('sha256', json_encode($material, JSON_THROW_ON_ERROR)), 0, 32);
    }

    /**
     * The assembled topology, for the diagnostics snapshot: which flows exist and which add-on owns
     * each event. Audit only — never read back as a source of truth.
     *
     * @return array<string, mixed>
     */
    public function toDefinition(): array
    {
        $flows = [];
        foreach ($this->definition->layers as $layerName => $layer) {
            $flows[$layerName] = array_keys($layer->flows);
        }

        return [
            'contributors'  => $this->contributorKeys,
            'flows'         => $flows,
            'bindings'      => array_map(
                static fn (EventBinding $binding): array => $binding->toDefinition(),
                $this->bindings->all(),
            ),
            'movementTypes' => array_map(
                static fn (array $types): array => array_map(
                    static fn (MovementTypeDefinition $type): string => $type->code,
                    $types,
                ),
                $this->movementTypes,
            ),
            'diagnostics'   => $this->bindings->diagnostics(),
        ];
    }
}
