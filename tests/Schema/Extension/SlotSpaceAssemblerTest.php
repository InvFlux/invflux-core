<?php

declare(strict_types=1);

namespace Tests\Schema\Extension;

use Nandan108\InvFlux\Exceptions\ConfigurationException;
use Nandan108\InvFlux\Flow\FlowDefinition;
use Nandan108\InvFlux\Registry\MovementTypeDefinition;
use Nandan108\InvFlux\Schema\DimensionValueDefinition;
use Nandan108\InvFlux\Schema\Extension\BaseFlowBindings;
use Nandan108\InvFlux\Schema\Extension\SlotSpaceAssembler;
use Nandan108\InvFlux\Schema\Extension\SlotSpaceContribution;
use Nandan108\InvFlux\Schema\Extension\SlotSpaceContributor;
use Nandan108\InvFlux\Schema\SlotSpaceFactory;
use Nandan108\InvFlux\Schema\Stt;
use PHPUnit\Framework\TestCase;

final class SlotSpaceAssemblerTest extends TestCase
{
    private const COMMERCIAL = SlotSpaceFactory::LAYER_COMMERCIAL;

    /** The native `stt` set lives in the DB, so the assembler is told what is already registered. */
    private const KNOWN = ['stt' => Stt::NATIVE];

    public function testAContributedFlowReachesTheDefinitionAndTheCompiledSpace(): void
    {
        $assembled = $this->assemble([
            $this->contributor('qi', 100, static function (SlotSpaceContribution $c): void {
                $c->requireValues('stt', new DimensionValueDefinition('qi', ownerKey: 'qi'))
                    ->addFlow(self::COMMERCIAL, FlowDefinition::define('qi_pass')->move(['stt' => 'qi'], ['stt' => Stt::ATP]))
                    ->registerMovementTypes('qi', 'QI_PASS')
                    ->bindEvent('inspection.passed', 'qi_pass', 'QI_PASS');
            }),
        ]);

        $commercial = $assembled->definition->layers[self::COMMERCIAL];
        self::assertArrayHasKey('qi_pass', $commercial->flows);
        self::assertSame(['QI_PASS'], array_map(
            static fn (MovementTypeDefinition $t): string => $t->code,
            $assembled->movementTypes['qi'],
        ));
        self::assertSame([BaseFlowBindings::KEY, 'qi'], $assembled->contributorKeys, 'core folds first, always');

        $binding = $assembled->bindings->resolve('inspection.passed');
        self::assertNotNull($binding);
        self::assertSame('qi_pass', $binding->flow);
        self::assertSame('qi', $binding->contributorKey);

        // The base flows survive the fold — a contributor adds, it does not replace.
        self::assertArrayHasKey('reserve', $commercial->flows);
        self::assertArrayHasKey(SlotSpaceFactory::FLOW_WRITE_IN, $commercial->flows);
    }

    /** Two add-ons that know nothing about each other must both survive the pass. */
    public function testTwoContributorsCoexistAndFoldInPriorityOrder(): void
    {
        $assembled = $this->assemble([
            $this->contributor('third_party', 1000, static function (SlotSpaceContribution $c): void {
                $c->addFlow(self::COMMERCIAL, FlowDefinition::define('late')->create(['stt' => Stt::ATP]));
            }),
            $this->contributor('first_party', 100, static function (SlotSpaceContribution $c): void {
                $c->addFlow(self::COMMERCIAL, FlowDefinition::define('early')->create(['stt' => Stt::ATP]));
            }),
        ]);

        self::assertSame([BaseFlowBindings::KEY, 'first_party', 'third_party'], $assembled->contributorKeys, 'low priority folds first');
        $flows = $assembled->definition->layers[self::COMMERCIAL]->flows;
        self::assertArrayHasKey('early', $flows);
        self::assertArrayHasKey('late', $flows);
    }

    /** Equal priority is a genuine tie, and registration order is the documented tie-break. */
    public function testEqualPrioritiesFoldInRegistrationOrder(): void
    {
        $assembled = $this->assemble([
            $this->contributor('b', 100, static fn (SlotSpaceContribution $c) => null),
            $this->contributor('a', 100, static fn (SlotSpaceContribution $c) => null),
        ]);

        self::assertSame([BaseFlowBindings::KEY, 'b', 'a'], $assembled->contributorKeys);
    }

    /** A flow name is a claim on meaning; two add-ons cannot both make it. */
    public function testADuplicateFlowNameIsRefusedAndNamesTheContributor(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('Contributor "second" cannot add flow "reserve"');

        $this->assemble([
            $this->contributor('second', 100, static function (SlotSpaceContribution $c): void {
                $c->addFlow(self::COMMERCIAL, FlowDefinition::define('reserve')->create(['stt' => Stt::ATP]));
            }),
        ]);
    }

    /**
     * The failure that this check exists to prevent arrives months later on a merchant's site, as
     * "not valid for dimension" at a first movement, with nothing naming the add-on responsible.
     */
    public function testAnUndeclaredSlotValueIsRefusedAtAssembly(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('unregistered value "bkd" in dimension "stt"');

        $this->assemble([
            $this->contributor('blocked', 100, static function (SlotSpaceContribution $c): void {
                $c->addFlow(self::COMMERCIAL, FlowDefinition::define('block')->move(['stt' => Stt::ATP], ['stt' => 'bkd']));
            }),
        ]);
    }

    /** Declaring it is all it takes — the same flow passes once the value is required. */
    public function testDeclaringTheValueMakesTheSameFlowAcceptable(): void
    {
        $assembled = $this->assemble([
            $this->contributor('blocked', 100, static function (SlotSpaceContribution $c): void {
                $c->requireValues('stt', new DimensionValueDefinition('bkd', ownerKey: 'blocked'))
                    ->addFlow(self::COMMERCIAL, FlowDefinition::define('block')->move(['stt' => Stt::ATP], ['stt' => 'bkd']));
            }),
        ]);

        self::assertArrayHasKey('block', $assembled->definition->layers[self::COMMERCIAL]->flows);
        self::assertSame(['bkd'], array_map(
            static fn (DimensionValueDefinition $v): string => $v->code,
            $assembled->requiredValues['stt'],
        ));
    }

    /**
     * `loc` values are merchant data. A contributor naming one has either hard-coded somebody
     * else's warehouse, or written a diagonal hop that should have been two single-axis flows.
     */
    public function testALiteralLocationInAPatternIsRefused(): void
    {
        $this->expectException(ConfigurationException::class);
        // Reported on the first literal met — the source side here — not only the destination.
        $this->expectExceptionMessage('names location "sup" literally in flow "putaway"');

        $this->assemble([
            $this->contributor('putaway', 100, static function (SlotSpaceContribution $c): void {
                $c->addFlow(self::COMMERCIAL, FlowDefinition::define('putaway')->move(['loc' => 'sup'], ['loc' => 'oh/main']));
            }),
        ]);
    }

    /** A `{param}` is resolved at execute time, so it is not a literal and must pass. */
    public function testAParameterizedLocationIsAccepted(): void
    {
        $assembled = $this->assemble([
            $this->contributor('transfer', 100, static function (SlotSpaceContribution $c): void {
                $c->addFlow(self::COMMERCIAL, FlowDefinition::define('transfer')->move(['loc' => '{from}'], ['loc' => '{to}']));
            }),
        ]);

        self::assertArrayHasKey('transfer', $assembled->definition->layers[self::COMMERCIAL]->flows);
    }

    /** Binding an event to a flow nobody registered is a typo, and reads as one. */
    public function testABindingNamingNoRegisteredFlowIsRefused(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('binds event "order.dispatched" to flow "no_such_flow"');

        $this->assemble([
            $this->contributor('pdj', 100, static function (SlotSpaceContribution $c): void {
                $c->bindEvent('order.dispatched', 'no_such_flow', 'DISPATCH');
            }),
        ]);
    }

    /** A binding may name a flow a later contributor adds — declaration order must not decide. */
    public function testABindingMayNameAFlowAddedByALaterContributor(): void
    {
        $assembled = $this->assemble([
            $this->contributor('binder', 100, static function (SlotSpaceContribution $c): void {
                $c->bindEvent('order.dispatched', 'to_transit', 'DISPATCH');
            }),
            $this->contributor('definer', 200, static function (SlotSpaceContribution $c): void {
                $c->addFlow(self::COMMERCIAL, FlowDefinition::define('to_transit')->destroy(['stt' => Stt::CTD]));
            }),
        ]);

        self::assertSame('to_transit', $assembled->bindings->resolve('order.dispatched')?->flow);
    }

    /** Higher priority wins, and the loss is recorded — a silent override is the thing to avoid. */
    public function testAHigherPriorityRebindWinsAndIsReported(): void
    {
        $assembled = $this->assemble([
            $this->contributor('base_addon', 100, static function (SlotSpaceContribution $c): void {
                $c->addFlow(self::COMMERCIAL, FlowDefinition::define('a')->create(['stt' => Stt::ATP]))
                    ->bindEvent('order.dispatched', 'a', 'DISPATCH');
            }),
            $this->contributor('later_addon', 1000, static function (SlotSpaceContribution $c): void {
                $c->addFlow(self::COMMERCIAL, FlowDefinition::define('b')->create(['stt' => Stt::ATP]))
                    ->bindEvent('order.dispatched', 'b', 'DISPATCH');
            }),
        ]);

        self::assertSame('later_addon', $assembled->bindings->resolve('order.dispatched')?->contributorKey);
        self::assertStringContainsString('overrides "base_addon"', implode("\n", $assembled->bindings->diagnostics()));
    }

    /** Two claims at the same rank have no honest tie-break, so neither is chosen. */
    public function testTwoContributorsBindingOneEventAtTheSamePriorityIsAmbiguous(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('both bind event "order.dispatched" at priority 100');

        $this->assemble([
            $this->contributor('one', 100, static function (SlotSpaceContribution $c): void {
                $c->addFlow(self::COMMERCIAL, FlowDefinition::define('a')->create(['stt' => Stt::ATP]))
                    ->bindEvent('order.dispatched', 'a', 'DISPATCH');
            }),
            $this->contributor('two', 100, static function (SlotSpaceContribution $c): void {
                $c->addFlow(self::COMMERCIAL, FlowDefinition::define('b')->create(['stt' => Stt::ATP]))
                    ->bindEvent('order.dispatched', 'b', 'DISPATCH');
            }),
        ]);
    }

    /** A contributor that self-gates off must leave the base exactly as it was. */
    public function testAContributorThatGatesItselfOffChangesNothing(): void
    {
        $bare = $this->assemble([]);
        $gated = $this->assemble([
            $this->contributor('disabled', 100, static function (SlotSpaceContribution $c): void {
                // Feature off — return without declaring anything.
            }),
        ]);

        self::assertSame(
            array_keys($bare->definition->layers[self::COMMERCIAL]->flows),
            array_keys($gated->definition->layers[self::COMMERCIAL]->flows),
        );
        self::assertSame([], $gated->requiredValues);
        self::assertSame([], $gated->movementTypes);
    }

    /** Two add-ons may need the same value; declaring it twice is normal, not a conflict. */
    public function testTheSameValueDeclaredTwiceIsMergedOnce(): void
    {
        $value = static fn (): DimensionValueDefinition => new DimensionValueDefinition('qi', ownerKey: 'shared');

        $assembled = $this->assemble([
            $this->contributor('one', 100, static fn (SlotSpaceContribution $c) => $c->requireValues('stt', $value())),
            $this->contributor('two', 200, static fn (SlotSpaceContribution $c) => $c->requireValues('stt', $value())),
        ]);

        self::assertCount(1, $assembled->requiredValues['stt']);
    }

    /** Declaring the *same code* with different attributes is two add-ons disagreeing. */
    public function testTheSameCodeDeclaredDifferentlyIsRefused(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('declare "qi" in dimension "stt" differently');

        $this->assemble([
            $this->contributor('one', 100, static fn (SlotSpaceContribution $c) => $c->requireValues(
                'stt',
                new DimensionValueDefinition('qi', ownerKey: 'one'),
            )),
            $this->contributor('two', 200, static fn (SlotSpaceContribution $c) => $c->requireValues(
                'stt',
                new DimensionValueDefinition('qi', ownerKey: 'two'),
            )),
        ]);
    }

    public function testTwoContributorsSharingAKeyIsRefused(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('share the key "clash"');

        $this->assemble([
            $this->contributor('clash', 100, static fn (SlotSpaceContribution $c) => null),
            $this->contributor('clash', 200, static fn (SlotSpaceContribution $c) => null),
        ]);
    }

    /**
     * The provisioning marker must change when an add-on is activated — that is the whole point —
     * and must NOT change between two identical boots, or every request re-provisions.
     */
    public function testTheProvisioningFingerprintTracksContributionsAndIsStable(): void
    {
        $withAddon = fn (): string => $this->assemble([
            $this->contributor('qi', 100, static fn (SlotSpaceContribution $c) => $c
                ->requireValues('stt', new DimensionValueDefinition('qi', ownerKey: 'qi'))
                ->registerMovementTypes('qi', 'QI_PASS')),
        ])->provisioningFingerprint('2');

        $bare = $this->assemble([])->provisioningFingerprint('2');

        self::assertSame($withAddon(), $withAddon(), 'two identical assemblies fingerprint identically');
        self::assertNotSame($bare, $withAddon(), 'activating an add-on changes the marker');
        self::assertNotSame($bare, $this->assemble([])->provisioningFingerprint('3'), 'so does a core version bump');
    }

    /** Declaration order must not perturb the fingerprint — it is canonicalized before hashing. */
    public function testTheFingerprintIgnoresDeclarationOrder(): void
    {
        $codes = static fn (string ...$codes): \Closure => static function (SlotSpaceContribution $c) use ($codes): void {
            $c->requireValues('stt', ...array_map(
                static fn (string $code): DimensionValueDefinition => new DimensionValueDefinition($code, ownerKey: 'many'),
                $codes,
            ));
            $c->registerMovementTypes('many', ...array_reverse($codes));
        };

        self::assertSame(
            $this->assemble([$this->contributor('many', 100, $codes('qi', 'bkd', 'pnd'))])->provisioningFingerprint('2'),
            $this->assemble([$this->contributor('many', 100, $codes('pnd', 'qi', 'bkd'))])->provisioningFingerprint('2'),
        );
    }

    /** @param list<SlotSpaceContributor> $contributors */
    private function assemble(array $contributors): \Nandan108\InvFlux\Schema\Extension\AssembledSlotSpace
    {
        return (new SlotSpaceAssembler(new SlotSpaceFactory(), $contributors, self::KNOWN))->assemble();
    }

    /**
     * @param \Closure(SlotSpaceContribution): mixed $contribute
     *
     * @psalm-param non-empty-string                       $key
     */
    private function contributor(string $key, int $priority, \Closure $contribute): SlotSpaceContributor
    {
        return new class($key, $priority, $contribute) implements SlotSpaceContributor {
            /**
             * @param \Closure(SlotSpaceContribution): mixed $contribute
             *
             * @psalm-param non-empty-string                       $key
             */
            public function __construct(
                private readonly string $key,
                private readonly int $priority,
                private readonly \Closure $contribute,
            ) {
            }

            #[\Override]
            public function key(): string
            {
                return $this->key;
            }

            #[\Override]
            public function priority(): int
            {
                return $this->priority;
            }

            #[\Override]
            public function contribute(SlotSpaceContribution $contribution): void
            {
                ($this->contribute)($contribution);
            }
        };
    }
}
