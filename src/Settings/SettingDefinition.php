<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Settings;

/**
 * Catalog entry for one known setting key.
 *
 * The catalog is PHP-declared (same shape as `BuiltInCorrectionTypes`,
 * `BuiltInOrderRefTypes`, …) and lives in {@see SettingsCatalog}. Core
 * declares its own keys; the adapter merges its own; add-ons extend via the
 * `invflux_container_built` hook before bootstrap.
 *
 * Every settable key must have a `SettingDefinition` — the catalog is strict
 * by design. `SettingsStore::set()` throws when the key is uncataloged.
 *
 * Beyond the substrate fields (name/default/policy), a definition carries the
 * Settings-UI schema: the control slug + config, the outline grouping, the
 * license gate, an optional value validator, and an optional advisory — the
 * advice this setting offers when the merchant's current value warrants some.
 * These are all catalog-side — the `invflux_settings` row stays dumb.
 *
 * The advisory is a field here rather than a list kept elsewhere so that a count
 * of settings wanting attention is a projection over these same definitions.
 * Two structures describing one thing disagree, and they disagree toward the
 * staler copy.
 *
 * @api
 */
final class SettingDefinition
{
    /**
     * @param string                                                       $name            Setting key (dotted convention, e.g. `federation.node_id`)
     * @param mixed                                                        $defaultValue    Returned by `SettingsStore::getValue()` when no row exists; encoded with `json_encode()` shape (scalars, arrays, JSON-serializable objects)
     * @param ReplicationPolicy                                            $defaultPolicy   Catalog's default replication policy when no merchant override is set
     * @param bool                                                         $policyLocked    When `true`, the merchant cannot override `$defaultPolicy` via `merchant_choice`
     * @param string|null                                                  $description     Human-readable description for admin surfaces; not persisted on the row
     * @param string                                                       $dataType        Settings-UI control slug. Defaults to `json` — the universal fallback control
     * @param array<string, mixed>                                         $config          Control configuration (enum `options`, numeric `min`/`max`, …); shape is per-`dataType`
     * @param string|null                                                  $title           Human label for the UI, distinct from the dotted key
     * @param string|null                                                  $group           Outline path, 1–2 levels (`Dispatch` or `Dispatch/Confirmation`); feeds the sidebar + breadcrumb
     * @param int                                                          $order           Sort order within the group
     * @param string|null                                                  $featureKey      `LicenseGate` feature key gating editability; `null` = ungated (Essentials)
     * @param string|null                                                  $tier            Display-only label naming what unlocks the setting; rendered only when locked. Inert without `$featureKey`
     * @param list<string>                                                 $scopes          Scopes at which the setting may be overridden; cascade reserved, `['site']` today
     * @param bool                                                         $hidden          When `true`, the setting is omitted from the Settings UI surface entirely (not projected, not writable via the settings REST endpoint) — for system-managed/internal keys. The substrate (`SettingsStore::get`/`set`) still works for internal callers
     * @param (\Closure(mixed): (string|null))|null                        $validator       Server-authoritative value check run in `SettingsStore::set()` before persist; returns `null` when valid, or a human reason string when invalid (→ {@see InvalidSettingValue})
     * @param (\Closure(): list<array{value: string, label: string}>)|null $optionsProvider Lazily resolves the control's options at projection (request) time — for dynamic `enum`/`multiselect` choices (e.g. registered taxonomies) that can't be baked into the boot-resolved catalog. The projector calls it and overrides `config['options']`
     * @param (\Closure(mixed): (SettingAdvisory|null))|null               $advisory        Asked of the **effective value** at projection (request) time: returns advice when this merchant's current value warrants it, `null` when it does not. Message included, so it is built where translation is safe. Advice about a value clears itself on save; advice about a *setting* could not
     */
    public function __construct(
        public readonly string $name,
        public readonly mixed $defaultValue,
        public readonly ReplicationPolicy $defaultPolicy,
        public readonly bool $policyLocked = false,
        public readonly ?string $description = null,
        public readonly string $dataType = 'json',
        public readonly array $config = [],
        public readonly ?string $title = null,
        public readonly ?string $group = null,
        public readonly int $order = 0,
        public readonly ?string $featureKey = null,
        public readonly ?string $tier = null,
        public readonly array $scopes = ['site'],
        public readonly bool $hidden = false,
        public readonly ?\Closure $validator = null,
        public readonly ?\Closure $optionsProvider = null,
        public readonly ?\Closure $advisory = null,
    ) {
        if ('' === $name) {
            throw new \InvalidArgumentException('SettingDefinition.name must be a non-empty string.');
        }
        if (\strlen($name) > 128) {
            throw new \InvalidArgumentException(\sprintf(
                'SettingDefinition.name "%s" is %d chars; max is 128 (matches invflux_settings.name VARCHAR(128) PK).',
                $name,
                \strlen($name),
            ));
        }
    }
}
