<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Contracts;

/**
 * Turns the references recorded against one kind of actor into names a person can read.
 *
 * An event records who caused it as an actor *kind* plus an opaque *reference* — `user` + `3`,
 * `plugin` + `woocommerce`, `supplier` + a supplier id. That is the right shape for a durable
 * record: it stays true when a person is renamed, and it does not bind the event store to whatever
 * system happens to own identities today. It is also unreadable, which is what this repairs.
 *
 * Resolution is deliberately *not* built into the event store. Whoever registers an actor kind
 * knows how to name it and nobody else does — the host adapter can turn a user id into an account
 * name, an add-on that introduces supplier actors can turn a supplier id into a company. Each
 * contributes a resolver for its own kind; kinds with no resolver simply keep showing the
 * reference.
 *
 * **Bulk by construction.** The method takes every reference on the page at once because the
 * alternative is one lookup per row, and a timeline is hundreds of rows over a handful of distinct
 * actors. There is deliberately no single-reference method to reach for by mistake.
 *
 * Best-effort throughout: a reference that cannot be resolved is omitted from the result rather
 * than mapped to a placeholder. The caller then falls back to the reference itself, which is the
 * only identifying thing the record still holds — inventing "Unknown user" would discard it.
 *
 * @api
 */
interface ActorNameResolver
{
    /**
     * Map actor references to display names.
     *
     * Implementations must not throw for an unknown or malformed reference, and must not assume
     * every reference resolves. Returning fewer entries than asked for is normal and expected —
     * accounts are deleted, add-ons are deactivated with their data left behind.
     *
     * @param list<string> $refs distinct actor references, as recorded on the events
     *
     * @return array<array-key, string> reference => display name, omitting anything unresolvable.
     *                                  `array-key` and not `string` because PHP silently coerces a
     *                                  numeric-string array key to an int, so a resolver returning
     *                                  `'1' => 'Sam'` really returns `1 => 'Sam'`. Lookups coerce
     *                                  the same way, so it round-trips — but the type has to admit
     *                                  what the language actually stores.
     */
    public function namesFor(array $refs): array;
}
