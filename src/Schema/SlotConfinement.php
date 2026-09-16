<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Schema;

use Nandan108\InvFlux\Exceptions\ConfigurationException;

/**
 * "This value may only occur here" — a placement constraint on one dimension value.
 *
 * The declarative form of {@see \Nandan108\SlotFlow\SlotSpace::confine()}, carrying the contributor
 * that asked for it so a slot's absence can be explained rather than merely observed.
 *
 * **A constraint, not a rule.** Confinements intersect and commute: two over different values never
 * interact, two over the same value both hold, and applying one twice changes nothing. That is what
 * makes them safe to assemble from add-ons which cannot see each other — unlike a rule sequence,
 * where meaning depends on a base and an order no single contributor controls.
 *
 * The two kinds §5.3 distinguishes have the same shape here and differ in who may relax them: a
 * **semantic impossibility** (`pnd` outside an inbound leg contradicts what `pnd` means) is
 * declared by the value's own add-on and is not configurable; an **operational segregation**
 * (inspection stock kept in a service area) is policy, and the add-on declares it from merchant
 * settings — so it varies by install rather than by code.
 *
 * @api
 */
final class SlotConfinement
{
    /** @psalm-var non-empty-string */
    public readonly string $dimension;

    /** @psalm-var non-empty-string */
    public readonly string $value;

    /** @psalm-var non-empty-string */
    public readonly string $axis;

    /** @psalm-var list<non-empty-string> */
    public readonly array $allowed;

    /** @psalm-var non-empty-string */
    public readonly string $contributor;

    /**
     * @param string                 $dimension   dimension owning the constrained value
     * @param string                 $value       the value whose placement is constrained
     * @param string                 $axis        the dimension the constraint reads
     * @param list<non-empty-string> $allowed     the only `$axis` values `$value` may sit on
     * @param non-empty-string       $contributor who declared it — for diagnostics, never behaviour
     */
    public function __construct(
        string $dimension,
        string $value,
        string $axis,
        array $allowed,
        string $contributor = 'core',
    ) {
        if ('' === $dimension || '' === $axis || '' === $value) {
            throw new ConfigurationException(
                'A confinement needs a dimension, a value and an axis.',
                'incomplete_confinement',
            );
        }

        if ($dimension === $axis) {
            throw new ConfigurationException(
                sprintf('Confinement of "%s=%s" reads its own dimension — it constrains nothing.', $dimension, $value),
                'self_referential_confinement',
                ['dimension' => $dimension, 'value' => $value],
            );
        }

        if ([] === $allowed) {
            throw new ConfigurationException(
                sprintf('Confining "%s=%s" to no %s value leaves it nowhere to exist.', $dimension, $value, $axis),
                'empty_confinement',
                ['dimension' => $dimension, 'value' => $value, 'axis' => $axis],
            );
        }

        $this->dimension = $dimension;
        $this->value = $value;
        $this->axis = $axis;
        $this->allowed = $allowed;
        $this->contributor = $contributor;
    }

    /** Whether this constraint has anything to say about a layer carrying these dimensions. */
    public function appliesTo(string ...$dimensionNames): bool
    {
        return in_array($this->dimension, $dimensionNames, true)
            && in_array($this->axis, $dimensionNames, true);
    }
}
