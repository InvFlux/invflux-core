<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Schema\Extension;

use Nandan108\InvFlux\Exceptions\ConfigurationException;

/**
 * Which flow each domain event executes, and who decided that.
 *
 * **Enumerable on purpose.** The value of making the binding data rather than a literal at the call
 * site is that "which add-on owns *dispatched*, and what does its movement type drag along?" is a
 * question you can answer by reading, instead of by archaeology through three plugins.
 *
 * @api
 */
final class EventBindingRegistry
{
    /** @var array<non-empty-string, EventBinding> */
    private array $bindings = [];

    /** @var list<string> */
    private array $diagnostics = [];

    /**
     * Record a binding, or refuse it.
     *
     * Higher priority wins and **says so** — a silent win is how a merchant ends up with an add-on
     * they forgot they installed deciding what dispatch means. Equal priority is ambiguous and
     * throws: there is no honest tie-break between two contributors that both claim the same event
     * at the same rank, and inventing one (registration order, alphabetical) would make the
     * outcome depend on something neither author can see.
     */
    public function bind(EventBinding $binding): void
    {
        $existing = $this->bindings[$binding->event] ?? null;

        if (null !== $binding->expect
            && null !== $existing
            && $existing->contributorKey !== $binding->expect) {
            // A warning, not a refusal: the binding may still be exactly what the merchant wants.
            // What it cannot be is *unnoticed* — this is the case where a third party rebound an
            // event that another add-on had already rebound, and neither knows about the other.
            $this->diagnostics[] = sprintf(
                'Contributor "%s" expected event "%s" to be owned by "%s", but it is owned by "%s".',
                $binding->contributorKey,
                $binding->event,
                $binding->expect,
                $existing->contributorKey,
            );
        }

        if (null === $existing) {
            $this->bindings[$binding->event] = $binding;

            return;
        }

        if ($binding->priority === $existing->priority) {
            throw new ConfigurationException(
                sprintf(
                    'Contributors "%s" and "%s" both bind event "%s" at priority %d.',
                    $existing->contributorKey,
                    $binding->contributorKey,
                    $binding->event,
                    $binding->priority,
                ),
                'ambiguous_event_binding',
                [
                    'event'        => $binding->event,
                    'contributors' => [$existing->contributorKey, $binding->contributorKey],
                    'priority'     => $binding->priority,
                ],
            );
        }

        $winner = $binding->priority > $existing->priority ? $binding : $existing;
        $loser = $winner === $binding ? $existing : $binding;

        $this->diagnostics[] = sprintf(
            'Event "%s" bound by "%s" (priority %d) overrides "%s" (priority %d).',
            $binding->event,
            $winner->contributorKey,
            $winner->priority,
            $loser->contributorKey,
            $loser->priority,
        );

        $this->bindings[$binding->event] = $winner;
    }

    /**
     * The binding in force for one event, or null when nothing claims it.
     *
     * **Pure — no I/O.** Whatever an answer depends on is passed in as `$context` (the order, the
     * receipt line, the supplier), never fetched here: a router that queries is a router you cannot
     * call from inside a transaction without thinking about it.
     *
     * `$context` is accepted but unused today, and that is deliberate rather than an oversight.
     * Predicated bindings — an inspection gate that routes only for some suppliers and otherwise
     * falls through to the default — are a real requirement, but nothing consumes one yet, and a
     * parameter shaped by guesswork is worse than a parameter that is not there. Taking it now
     * means the call sites are already written in the shape predicates will need, so adding them
     * later is internal to this class.
     *
     * @psalm-suppress UnusedParam — $context is the seam predicates will read; see above
     */
    public function resolve(string $event, mixed $context = null): ?EventBinding
    {
        return $this->bindings[$event] ?? null;
    }

    /** @return array<non-empty-string, EventBinding> */
    public function all(): array
    {
        return $this->bindings;
    }

    /**
     * Human-readable notes about overrides and mismatched `expect:` claims, in the order they
     * happened. Surfaced by diagnostics; never a source of truth.
     *
     * @return list<string>
     */
    public function diagnostics(): array
    {
        return $this->diagnostics;
    }
}
