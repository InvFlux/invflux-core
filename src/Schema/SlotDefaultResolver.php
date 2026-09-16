<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Schema;

/**
 * Resolve the default value of one dimension, for one layer, for one purpose.
 *
 * Replaces hardcoded slot-value constants at the call site. A caller that wants "the
 * on-hand location" must ask for it rather than naming `oh`, because the right answer
 * changes with what is installed and with what the caller is doing:
 *
 * | installed                | `loc` default              |
 * | ------------------------ | -------------------------- |
 * | base only                | `oh`                       |
 * | + multi-warehouse        | `oh/main`                  |
 * | + bin-level hierarchy    | `oh/main/unassigned`, or a receiving dock for a goods receipt |
 *
 * Naming the value at the call site makes every one of those a breaking change; asking a
 * resolver makes them a binding change in one place. It is also what lets a location rename
 * stay a metadata operation — slot identity is a surrogate id, so renaming a dimension value
 * preserves `inventory_state` and the ledger, but only if no caller hardcoded the old code.
 *
 * **Failure is loud by contract.** An unknown layer, dimension, or purpose throws rather than
 * falling back. A wrong default silently routes stock to the wrong place, which is worse to
 * discover later than a failed operation is to handle now.
 *
 * @api
 */
interface SlotDefaultResolver
{
    /**
     * @param string             $layer     a layer name, e.g. `SlotSpaceFactory::LAYER_COMMERCIAL`
     * @param string             $dimension a dimension name, e.g. `loc`
     * @param SlotPurpose|string $purpose   what the value is for; a resolver that does not
     *                                      recognise the purpose MUST throw, never guess
     *
     * @return non-empty-string the dimension value to use
     *
     * @throws \Nandan108\InvFlux\Exceptions\SchemaException on unknown layer, dimension or
     *                                                       purpose, or when the dimension
     *                                                       declares no default
     */
    public function resolve(
        string $layer,
        string $dimension,
        SlotPurpose | string $purpose = SlotPurpose::Default_,
    ): string;
}
