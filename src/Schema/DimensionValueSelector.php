<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Schema;

use Nandan108\InvFlux\Exceptions\ConfigurationException;

/**
 * Declares which subset of a hierarchical dimension's values a layer addresses.
 *
 * @api
 */
final class DimensionValueSelector
{
    /** @psalm-param list<non-empty-string> $levels */
    private function __construct(
        public readonly DimensionValueSelectorKind $kind,
        public readonly ?string $level = null,
        public readonly array $levels = [],
    ) {
    }

    /** Only values at the root of the hierarchy (parent_id IS NULL). */
    public static function root(): self
    {
        return new self(DimensionValueSelectorKind::Root);
    }

    /** Only values tagged with a specific structural level name (e.g. 'warehouse'). */
    public static function level(string $level): self
    {
        if ('' === $level) {
            throw new ConfigurationException('Level name must be a non-empty string.', 'empty_selector_level');
        }

        return new self(DimensionValueSelectorKind::Level, $level);
    }

    /**
     * Values tagged with **any** of several structural level names.
     *
     * A layer sometimes addresses more than one kind of place at the same grain. The commercial
     * layer is the standing example: it addresses warehouses, and it must equally address the
     * locations that are not warehouses and never will be — supplier-side stock, transit legs, the
     * customer's hands. Those are a different kind of place, not a different depth, so they carry
     * their own level name rather than being mislabelled as warehouses.
     *
     * Level matching is **depth-independent** — a warehouse nested under merchant-declared grouping
     * tiers still matches `warehouse` — which is what lets one level name stay honest as the tree
     * deepens beneath and above it. Selecting several names is the other half of that: breadth
     * across kinds, where {@see level()} gives depth-independence within one kind.
     *
     * Prefer this over widening an existing level's meaning. A level name that has grown to cover
     * places it does not describe is a lie the schema then has to maintain.
     *
     * @param string ...$levels at least one; duplicates collapse, order is not significant
     */
    public static function levels(string ...$levels): self
    {
        $unique = [];
        foreach ($levels as $level) {
            if ('' === $level) {
                throw new ConfigurationException('Level name must be a non-empty string.', 'empty_selector_level');
            }
            $unique[$level] = true;
        }

        if ([] === $unique) {
            throw new ConfigurationException('At least one level name is required.', 'empty_selector_levels');
        }

        /** @psalm-var list<non-empty-string> $names */
        $names = array_keys($unique);

        // One name is exactly what `level()` means. Collapsing keeps a single stored shape for a
        // single behaviour, so two equivalent declarations cannot read as a schema change.
        return 1 === count($names)
            ? new self(DimensionValueSelectorKind::Level, $names[0])
            : new self(DimensionValueSelectorKind::Levels, null, $names);
    }

    /** Only addressable leaf values (addressable = 1). */
    public static function leaf(): self
    {
        return new self(DimensionValueSelectorKind::Leaf);
    }

    /**
     * Deserialize from a JSON-decoded array.
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $kind = DimensionValueSelectorKind::from((string) ($data['kind'] ?? ''));

        return match ($kind) {
            DimensionValueSelectorKind::Level  => self::level((string) ($data['level'] ?? '')),
            DimensionValueSelectorKind::Levels => self::levels(...self::stringList($data['levels'] ?? null)),
            default                            => new self($kind),
        };
    }

    /**
     * Coerce a decoded JSON value into a list of level names.
     *
     * Deserialization reads whatever is stored, which is why this tolerates a malformed shape
     * rather than trusting it: an empty result reaches {@see levels()} and is refused there, so a
     * corrupt selector fails naming itself instead of silently selecting nothing.
     *
     * @return list<string>
     */
    private static function stringList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $out = [];
        /** @psalm-var mixed $item */
        foreach ($value as $item) {
            if (is_string($item)) {
                $out[] = $item;
            }
        }

        return $out;
    }

    /** @return array<string, string|list<string>> */
    public function toArray(): array
    {
        $out = ['kind' => $this->kind->value];
        if (null !== $this->level) {
            $out['level'] = $this->level;
        }
        if ([] !== $this->levels) {
            $out['levels'] = $this->levels;
        }

        return $out;
    }
}
