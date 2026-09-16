<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Schema\Extension;

use Nandan108\InvFlux\Exceptions\ConfigurationException;
use Nandan108\InvFlux\Schema\SlotSpaceFactory;

/**
 * Fold every registered contributor onto the base slot space, once per boot.
 *
 * Replaces the direct `SlotSpaceFactory::createLayered()` call in bootstrap. Contributors fold in
 * `priority()` order — core 0, first-party 100s, third-party 1000+ — ties in registration order.
 *
 * **Assemble once, at the top of boot, and feed every consumer from the result.** Three things
 * depend on it and they answer to different gates: the dimension *names* drive the schema
 * installer's column set, the dimension *values* are registered during provisioning, and the flows
 * are used per request with no persistence at all. Assembling inside provisioning alone would mean
 * a contributed dimension never gets its column, because the installer ran against a definition
 * that had not been contributed to yet.
 *
 * **Resolve it after `invflux_container_built`.** Contributors register during that action, so an
 * assembler resolved at plugin boot — or constructor-injected into anything that is — snapshots an
 * empty contributor set and every add-on silently vanishes.
 *
 * @api
 */
final class SlotSpaceAssembler
{
    /**
     * Container tag every contributor registers under.
     *
     * `Container::tag()` refuses an id that is not registered yet, so an add-on either `set()`s
     * then `tag()`s, or uses the one-call form: `$c->set(MyContributor::class, $factory, tag:
     * SlotSpaceAssembler::CONTRIBUTOR_TAG)`.
     */
    public const CONTRIBUTOR_TAG = 'invflux.slot_space_contributor';

    private ?AssembledSlotSpace $assembled = null;

    /**
     * @param iterable<SlotSpaceContributor>                  $contributors typically the container's tagged services
     * @param array<non-empty-string, list<non-empty-string>> $knownValues  already-registered values per DB-backed
     *                                                                      dimension, so contributed patterns can be
     *                                                                      checked against them (see
     *                                                                      {@see SlotSpaceContribution::__construct()})
     */
    public function __construct(
        private readonly SlotSpaceFactory $base,
        private readonly iterable $contributors = [],
        private readonly array $knownValues = [],
    ) {
    }

    /**
     * Assemble, memoized.
     *
     * Memoized because assembly must be *idempotent within a request* and because a second pass
     * would re-run every contributor — cheap for a well-behaved one, and a duplicate-flow failure
     * for one that appends rather than declares.
     */
    public function assemble(): AssembledSlotSpace
    {
        return $this->assembled ??= $this->run();
    }

    /**
     * The event bindings in force, assembling first if needed.
     *
     * The shorthand a call site wants: routing an event is the one thing done per request, and
     * `$this->assembler->assemble()->bindings->resolve(...)` at every seam reads as plumbing.
     */
    public function bindings(): EventBindingRegistry
    {
        return $this->assemble()->bindings;
    }

    private function run(): AssembledSlotSpace
    {
        /** @var list<array{contributor: SlotSpaceContributor, index: int}> $ordered */
        $ordered = [];
        /** @var array<string, string> $seen */
        $seen = [];
        $index = 0;
        // Core's own defaults first, always, and not via the tag. They answer for core's use cases
        // — a goods receipt refuses to move stock with no binding — so leaving them to be
        // registered would make "nobody wired it" indistinguishable from the misconfiguration that
        // refusal is there to catch. Priority 0, so an add-on overrides them the ordinary way.
        //
        // Built as a list rather than spread inline: the tagged set is a generator, and it is
        // traversed exactly once here.
        /** @var list<SlotSpaceContributor> $all */
        $all = [new BaseFlowBindings()];
        foreach ($this->contributors as $tagged) {
            $all[] = $tagged;
        }

        foreach ($all as $contributor) {
            $key = $contributor->key();

            if (isset($seen[$key])) {
                throw new ConfigurationException(
                    sprintf(
                        'Two contributors share the key "%s" (%s and %s).',
                        $key,
                        $seen[$key],
                        $contributor::class,
                    ),
                    'duplicate_contributor_key',
                    ['key' => $key],
                );
            }
            $seen[$key] = $contributor::class;

            // Registration index breaks priority ties deterministically. `usort` is not stable
            // across every PHP build in a way worth relying on, so the tie-break is explicit.
            $ordered[] = ['contributor' => $contributor, 'index' => $index];
            ++$index;
        }

        usort(
            $ordered,
            /**
             * @psalm-param array{contributor: SlotSpaceContributor, index: int} $a
             * @psalm-param array{contributor: SlotSpaceContributor, index: int} $b
             */
            static fn (array $a, array $b): int => $a['contributor']->priority() <=> $b['contributor']->priority()
                ?: $a['index'] <=> $b['index'],
        );

        $contribution = new SlotSpaceContribution($this->base->createLayered(), $this->knownValues);
        $keys = [];

        foreach ($ordered as $entry) {
            $contributor = $entry['contributor'];
            $key = $contributor->key();
            $keys[] = $key;
            $contribution->forContributor($key, $contributor->priority());
            $contributor->contribute($contribution);
        }

        return new AssembledSlotSpace(
            definition: $contribution->build(),
            bindings: $contribution->bindings(),
            requiredValues: $contribution->requiredValues(),
            movementTypes: $contribution->movementTypes(),
            contributorKeys: $keys,
        );
    }
}
