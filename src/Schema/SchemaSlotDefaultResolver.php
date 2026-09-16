<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Schema;

use Nandan108\InvFlux\Contracts\Inventory\SchemaManager;
use Nandan108\InvFlux\Exceptions\SchemaException;

/**
 * Default {@see SlotDefaultResolver} — answers from the **registered** schema.
 *
 * Reads the dimension's declared `defaultValue` out of the bootstrapped definition, which is
 * loaded from the dimension tables rather than from a PHP constant. So renaming a value
 * (`oh` → `oh/main`) or registering a different default changes every caller's answer with
 * no code change, which is the point of the indirection.
 *
 * ## Purpose is validated, not yet used
 *
 * Every canonical {@see SlotPurpose} resolves to the same value here: the dimension's
 * declared default. Purpose-specific routing — a goods receipt landing on a receiving dock
 * while a correction lands on the general holding leaf — needs a bin-level hierarchy to be
 * meaningful, so it belongs to the add-on that introduces one, via a resolver bound over
 * this one. An unrecognised purpose **string** throws here rather than silently resolving to
 * the default: an add-on that invents a purpose must also supply a resolver that understands
 * it, and finding that out at the call is better than finding it out in the ledger.
 *
 * ## The answer is per layer, because a dimension's default is not
 *
 * Layers address different subsets of a shared dimension — one selects warehouses by level,
 * another only addressable leaves — so a declared default is not necessarily *reachable* from
 * the layer being asked about. Deepen the location tree and they diverge: the declared `loc`
 * default names the warehouse, which the leaf-addressing layer cannot select, and a resolver
 * answering from the dimension alone would route stock at a slot that layer does not have.
 *
 * So this reads {@see SchemaManager::layerSchema()} rather than the flat schema. That applies
 * the layer's own value selector and, where the declared default falls outside it, answers with
 * a value the layer can actually address.
 *
 * @api
 */
final class SchemaSlotDefaultResolver implements SlotDefaultResolver
{
    /** Layers this resolver will answer for. Unknown names throw rather than resolving. */
    private const KNOWN_LAYERS = [
        SlotSpaceFactory::LAYER_COMMERCIAL,
        SlotSpaceFactory::LAYER_PHYSICAL,
    ];

    /** @psalm-var SchemaManager|\Closure(): SchemaManager */
    private SchemaManager | \Closure $schemaManager;

    /**
     * Accepts a manager or a **provider** of one. Prefer the provider: this resolver is a
     * constructor dependency of request-time services (controllers, correction handlers) that the
     * container builds just to register routes, so demanding a booted manager up front would boot
     * the store — schema convergence and reference seeding — on every request that merely wires
     * hooks. The provider defers that to the first {@see resolve()}, which is the only point the
     * schema is genuinely needed.
     *
     * @psalm-param SchemaManager|\Closure(): SchemaManager $schemaManager
     */
    public function __construct(SchemaManager | \Closure $schemaManager)
    {
        $this->schemaManager = $schemaManager;
    }

    /** Resolve (once) and memoise the manager, so repeated calls don't re-invoke the provider. */
    private function schemaManager(): SchemaManager
    {
        $manager = $this->schemaManager;
        if ($manager instanceof \Closure) {
            /** @psalm-var mixed $produced */
            $produced = $manager();
            $manager = $produced instanceof SchemaManager ? $produced : throw new SchemaException(
                'Slot-default schema provider did not return a SchemaManager.',
                'invalid_schema_provider',
                ['produced' => get_debug_type($produced)],
            );
            $this->schemaManager = $manager;
        }

        return $manager;
    }

    #[\Override]
    public function resolve(
        string $layer,
        string $dimension,
        SlotPurpose | string $purpose = SlotPurpose::Default_,
    ): string {
        if (!\in_array($layer, self::KNOWN_LAYERS, true)) {
            throw new SchemaException(
                \sprintf('Unknown slot layer "%s".', $layer),
                'unknown_slot_layer',
                ['layer' => $layer, 'known' => self::KNOWN_LAYERS],
            );
        }

        // A string purpose is accepted only when it names a canonical case. An add-on's own
        // purpose requires an add-on resolver — see the class docblock.
        if (\is_string($purpose) && null === SlotPurpose::tryFrom($purpose)) {
            throw new SchemaException(
                \sprintf('Unknown slot purpose "%s" — bind a resolver that understands it.', $purpose),
                'unknown_slot_purpose',
                ['purpose' => $purpose, 'layer' => $layer, 'dimension' => $dimension],
            );
        }

        $definition = $this->schemaManager()->layerSchema($layer)->dimensionByName($dimension);
        if (null === $definition) {
            throw new SchemaException(
                \sprintf('Unknown dimension "%s".', $dimension),
                'unknown_dimension',
                ['dimension' => $dimension, 'layer' => $layer],
            );
        }

        if ('' === $definition->defaultValue) {
            throw new SchemaException(
                \sprintf('Dimension "%s" declares no default value.', $dimension),
                'dimension_has_no_default',
                ['dimension' => $dimension, 'layer' => $layer],
            );
        }

        return $definition->defaultValue;
    }
}
