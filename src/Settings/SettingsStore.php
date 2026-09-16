<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Settings;

/**
 * Contract for the InvFlux-owned settings substrate (`invflux_settings`).
 *
 * Strict-mode by construction: implementations enforce that every key passed
 * to `set()` exists in the {@see SettingsCatalog}. The full contract — schema,
 * effective-policy resolution rule, Essentials vs Pro behavior, and what's
 * deliberately out of scope — is documented with the settings design.
 *
 * @api
 */
interface SettingsStore
{
    /**
     * Read a setting's value. Returns the catalog default when no row exists.
     *
     * @throws \InvalidArgumentException when `$name` is not in the catalog
     */
    public function getValue(string $name): mixed;

    /**
     * Read the raw row + policy context. Returns `null` when no row exists
     * (callers may still want to render the catalog default; use `getValue()`
     * for that path).
     */
    public function get(string $name): ?SettingValue;

    /**
     * Upsert a setting value. Resolves the effective replication policy from
     * `(catalog defaults, $merchantChoice)` at write time and persists it.
     *
     * Resolution rule:
     * 1. catalog `policyLocked = true` → effective = catalog default,
     *    merchant_choice column set to NULL
     * 2. else if `$merchantChoice` is non-null → effective = `$merchantChoice`
     * 3. else → effective = catalog default, merchant_choice = NULL
     *
     * @throws \InvalidArgumentException when `$name` is not in the catalog
     */
    public function set(string $name, mixed $value, ?ReplicationPolicy $merchantChoice = null): void;

    /**
     * Atomically upsert many settings: either all persist or none. Each item is
     * validated (strict-mode catalog lookup + its definition's validator) before
     * any write; a single failure rolls the whole batch back. Primary write path
     * for the Settings UI's bulk commit.
     *
     * @param list<array{name: string, value: mixed, merchantChoice?: ReplicationPolicy|null}> $items
     *
     * @throws \InvalidArgumentException when any `name` is not in the catalog
     * @throws InvalidSettingValue       when any value fails its validator
     */
    public function setBatch(array $items): void;

    /**
     * Delete the row. The next `getValue($name)` returns the catalog default.
     * No-op when the row doesn't exist.
     */
    public function delete(string $name): void;

    /**
     * Every currently-persisted row, in undefined order. Used by admin
     * surfaces + the future replication layer's "what should I send" sweep.
     *
     * @return list<SettingValue>
     */
    public function all(): array;
}
