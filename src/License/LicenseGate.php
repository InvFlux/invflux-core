<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\License;

/**
 * LicenseGate is the runtime feature-gate for tier-licensed functionality.
 *
 * Paid functionality ships in add-on plugins of its own; this plugin carries the gate and the
 * upsell, never the paid code. The gate is the
 * decision point: "is feature X allowed on this install?". Consumers call
 * `allows($featureKey)` at the relevant boundary (settings page rendering
 * a Pro option, REST endpoint accepting a Pro action, scheduled job
 * deciding whether to run a Pro path, etc.) and degrade to "greyed out +
 * Upgrade tooltip" / 4xx-with-tier-message rather than hard error.
 *
 * ## Feature-key conventions
 *
 * Feature keys are **kebab-case, namespaced by module**:
 *
 *     {module}.{feature-name}
 *
 * Examples:
 *
 *   - `lpsc.hold-for-merchant-review` — LPSC's Pro-only resolution policy
 *   - `dispatch.advanced-routing`     — Pro dispatch workbench routing rules
 *   - `workbench.bulk-creation`       — Pro ingestion / paste-from-Excel
 *
 * Modules use their plug-in / core-module slug (the same convention as
 * the column source-slug — e.g. `invflux_wb` columns gate against
 * `invflux_wb.<feature>` keys).
 *
 * Calls with unknown / mistyped keys are NOT silently allowed — the
 * concrete implementation SHOULD fail closed (`return false`) so typos
 * surface during development as features that "mysteriously refuse to
 * work", rather than as Pro paths silently leaking into Essentials installs.
 *
 * ## Forward-compatibility with the manifested-license architecture
 *
 * The grandfather model requires a per-license
 * feature manifest captured at license issuance. The future concrete
 * impl will evaluate `allows()` against that snapshot. The interface
 * signature here is deliberately minimal so consumers don't need
 * rewriting when the real impl lands — only the bound concrete changes.
 *
 * Future signature additions (e.g. a `mode: AccessMode` param for the
 * Read-vs-Write distinction the §10 revoked-license matrix implies)
 * will be optional, preserving caller compatibility.
 *
 * @api
 *
 * @see Entitlement
 */
interface LicenseGate
{
    /**
     * Returns true iff `$featureKey` is allowed on this install for the
     * given access `$mode`.
     *
     * `$mode` defaults to {@see AccessMode::Write} — the safe default, so a
     * bare `allows($key)` call at a mutation boundary fails closed under a
     * revoked / stale-degraded license. Read-only call sites (rendering a
     * view of paid-tier data) pass {@see AccessMode::Read} to stay visible
     * while writes are blocked, per the per-feature revoke matrix.
     *
     * Unknown / unregistered keys MUST return false (fail closed for
     * typo protection — see class docblock).
     */
    public function allows(string $featureKey, AccessMode $mode = AccessMode::Write): bool;

    /**
     * Whether the install holds an entitlement for `$sku`.
     *
     * **Display only** — badges, upsell copy, which bundle to load. Never gate
     * behaviour on it. Features are gated by key through `allows()` precisely
     * because the two answers legitimately disagree: a grandfathered install keeps
     * a feature whose SKU it does not hold, so a SKU check would take away
     * something the licence still grants.
     *
     * They disagree in the other direction too, and deliberately: this is a question
     * about **membership**, so it stays true through revocation and through offline
     * grace exhaustion, while `allows()` degrades each feature per the revoke matrix.
     * A merchant whose site could not reach us for a fortnight keeps the interface
     * they paid for, with the parts that must stop working stopping.
     *
     * Unknown SKUs return false, like `allows()` does for unknown keys.
     */
    public function holds(string $sku): bool;
}
