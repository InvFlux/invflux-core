<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Schema\Extension;

/**
 * An add-on's declaration of what it adds to the slot space.
 *
 * Registered as a tagged service (`invflux.slot_space_contributor`) while the container is being
 * built; {@see SlotSpaceAssembler} collects every one of them and folds their contributions onto
 * the base definition. **Nobody replaces the base factory** — an add-on adds, it does not rebuild.
 *
 * Two obligations that are easy to miss:
 *
 * - **Self-gate inside `contribute()`.** A contributor is registered because its add-on is
 *   *installed*, which is not the same as *enabled*. Read your own merchant setting and return
 *   early when the feature is off, leaving the base untouched — do not rely on the add-on's
 *   registration being conditional, because whether the service is registered is a boot-time
 *   question and whether the feature is on is a merchant one.
 * - **Contribute the same thing every boot.** The assembled values and movement types are
 *   fingerprinted to decide whether provisioning must re-run, so a contribution that varies with
 *   the request (a clock, a random id, an unsorted array) re-provisions on every request.
 *
 * @api
 */
interface SlotSpaceContributor
{
    /**
     * Stable, unique id — `'post_dispatch'`, `'pro_procurement'`.
     *
     * It orders nothing by itself (that is {@see priority()}), but it names this contributor in
     * every error and diagnostic, so a collision between two add-ons can be reported as a collision
     * between two *names* rather than two anonymous closures.
     *
     * @return non-empty-string
     */
    public function key(): string;

    /**
     * Fold order, low to high. Bands, documented rather than enforced: core 0, first-party 100s,
     * third-party 1000+.
     *
     * Ties fold in registration order, which is deterministic but not something to rely on — two
     * contributors that care about their relative order should say so with different priorities.
     */
    public function priority(): int;

    /** Declare values, rules, flows, movement types and event bindings. */
    public function contribute(SlotSpaceContribution $contribution): void;
}
