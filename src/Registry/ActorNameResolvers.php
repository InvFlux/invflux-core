<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Registry;

use Nandan108\InvFlux\Contracts\ActorNameResolver;

/**
 * Where each actor kind's {@see ActorNameResolver} is registered, and the one place that asks them.
 *
 * Pairs with {@see ActorTypeDefinition}: a system that introduces an actor kind may also say how to
 * name it. Registration is open — the host adapter contributes `user`, an add-on introducing
 * supplier or carrier actors contributes its own — and a kind with no resolver is not an error, it
 * simply keeps showing the reference.
 *
 * Resolution is per kind and in bulk. {@see self::resolve()} makes at most one call per *kind* for
 * a whole page of events, never one per event, because the shape a naive caller reaches for is a
 * lookup inside a row loop and the cost of that is invisible until a busy order.
 *
 * A resolver that throws is contained rather than allowed to take the page down: names are a
 * courtesy on a record that is perfectly legible without them, so a broken contribution costs its
 * own kind's names and nothing else.
 *
 * @api
 */
final class ActorNameResolvers
{
    /** @var array<string, ActorNameResolver> */
    private array $byKind = [];

    /**
     * Register the resolver for one actor kind, replacing any previous one.
     *
     * Last registration wins so a site or add-on can override the host's naming — the same
     * override semantics the container uses, and for the same reason: the later contributor knows
     * more about the install than the earlier one.
     */
    public function register(string $actorKind, ActorNameResolver $resolver): void
    {
        if ('' !== $actorKind) {
            $this->byKind[$actorKind] = $resolver;
        }
    }

    /** Whether any resolver is registered for this kind. */
    public function has(string $actorKind): bool
    {
        return isset($this->byKind[$actorKind]);
    }

    /**
     * Resolve names for many kinds at once.
     *
     * @param array<string, list<string>> $refsByKind actor kind => its distinct references
     *
     * @return array<string, array<array-key, string>> actor kind => (reference => display name);
     *                                                 see {@see ActorNameResolver::namesFor()}
     *                                                 for why the inner key is `array-key`
     */
    public function resolve(array $refsByKind): array
    {
        $out = [];
        foreach ($refsByKind as $kind => $refs) {
            $resolver = $this->byKind[$kind] ?? null;
            if (null === $resolver || [] === $refs) {
                continue;
            }
            try {
                $names = $resolver->namesFor(array_values(array_unique($refs)));
            } catch (\Throwable) {
                // A resolver is a courtesy; one that fails must not cost the caller its page.
                continue;
            }
            $clean = [];
            foreach ($names as $ref => $name) {
                if ('' !== $name) {
                    $clean[$ref] = $name;
                }
            }
            if ([] !== $clean) {
                $out[$kind] = $clean;
            }
        }

        return $out;
    }
}
