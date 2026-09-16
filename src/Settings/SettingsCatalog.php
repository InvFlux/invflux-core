<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Settings;

/**
 * Strict registry of known {@see SettingDefinition} keys.
 *
 * Assembled at boot from core seeds + adapter seeds + add-on contributions
 * (the latter via the `invflux_container_built` hook). `SettingsStore` writes
 * are gated on this catalog: `set('unknown.key', …)` throws via
 * {@see self::get()} so every setting must declare its replication intent
 * upfront.
 *
 * See arch-settings
 * §3 for the strict-mode rationale.
 *
 * @api
 */
final class SettingsCatalog
{
    /** @var array<string, SettingDefinition> indexed by name for O(1) lookup */
    private array $byName = [];

    /** @param list<SettingDefinition> $definitions */
    public function __construct(array $definitions)
    {
        foreach ($definitions as $def) {
            if (isset($this->byName[$def->name])) {
                throw new \InvalidArgumentException(\sprintf(
                    'SettingsCatalog duplicate name "%s" — each setting key must be declared exactly once.',
                    $def->name,
                ));
            }
            $this->byName[$def->name] = $def;
        }
    }

    public function has(string $name): bool
    {
        return isset($this->byName[$name]);
    }

    /**
     * @throws \InvalidArgumentException when `$name` is not in the catalog
     */
    public function get(string $name): SettingDefinition
    {
        return $this->byName[$name] ?? throw new \InvalidArgumentException(\sprintf(
            'SettingsCatalog has no entry for "%s". Every setting key must be declared via a SettingDefinition before set().',
            $name,
        ));
    }

    public function find(string $name): ?SettingDefinition
    {
        return $this->byName[$name] ?? null;
    }

    /** @return list<SettingDefinition> */
    public function all(): array
    {
        return array_values($this->byName);
    }
}
