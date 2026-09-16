<?php

declare(strict_types=1);

namespace Tests\Flow;

use Nandan108\InvFlux\Flow\Constraints\MaxFlowQuantity;
use Nandan108\InvFlux\Flow\Constraints\MaxSlotTotal;
use Nandan108\InvFlux\Flow\Constraints\MinSlotTotal;
use Nandan108\InvFlux\Flow\FlowDefinition;
use Nandan108\InvFlux\Schema\DimensionDefinition;
use Nandan108\InvFlux\Schema\SlotSpaceDefinition;
use Nandan108\SlotFlow\Policies\DimensionPriority;
use Nandan108\SlotFlow\QuantityState;
use Nandan108\SlotFlow\SlotSpace;
use PHPUnit\Framework\TestCase;

final class FlowDefinitionTest extends TestCase
{
    // -------------------------------------------------------------------------
    // Basic definition and compilation
    // -------------------------------------------------------------------------

    public function testDefineCompilesIntoUsableFlow(): void
    {
        $space = SlotSpace::define(['stt' => ['fs', 'res']]);
        $space->flow('reserve', static fn ($f) => $f->move('fs', 'res'));

        $def = FlowDefinition::define('reserve')
            ->move('fs', 'res');

        // Register via compiled flow on a fresh space
        $space2 = SlotSpace::define(['stt' => ['fs', 'res']]);
        $space2->flow($def->name(), static fn ($f) => $def->compile($f));

        $state = new QuantityState($space2, [[$space2->slot('fs'), 10]]);
        $engine = new \Nandan108\SlotFlow\MovementEngine();

        $result = $engine->execute($state, $space2, 'reserve', 4);

        self::assertTrue($result->isComplete());
        // Two deltas: source slot (-4) and dest slot (+4). Sum must be 0 (internal move).
        $deltaSum = array_sum(array_map(static fn ($d) => $d->delta, $result->deltas()));
        self::assertEquals(0, $deltaSum);
    }

    public function testToFlowProducesEquivalentFlow(): void
    {
        $flow = FlowDefinition::define('intake')->create('fs')->toFlow();

        self::assertSame('intake', $flow->name());
        self::assertCount(1, $flow->steps());
    }

    public function testEmptyNameThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        FlowDefinition::define('');
    }

    public function testNoStepBeforePolicyThrows(): void
    {
        $this->expectException(\LogicException::class);
        FlowDefinition::define('reserve')->orderBy(new DimensionPriority([]));
    }

    // -------------------------------------------------------------------------
    // Serializability tracking
    // -------------------------------------------------------------------------

    public function testIsSerializableByDefault(): void
    {
        $def = FlowDefinition::define('intake')->create('fs');
        self::assertTrue($def->isSerializable());
    }

    public function testCallableConstraintMarksNonSerializable(): void
    {
        $def = FlowDefinition::define('reserve')
            ->move('fs', 'res')
            ->constraint(static fn ($edge, $ctx): int => 5);

        self::assertFalse($def->isSerializable());
        self::assertFalse($def->toDefinition()['serializable']);
    }

    public function testCallableAllocationMarksNonSerializable(): void
    {
        $def = FlowDefinition::define('reserve')
            ->move('fs', 'res')
            ->allocate(static fn ($ctx): array => []);

        self::assertFalse($def->isSerializable());
    }

    public function testSerializablePolicyKeepsFlowSerializable(): void
    {
        $def = FlowDefinition::define('reserve')
            ->move('fs', 'res')
            ->constraint(MinSlotTotal::define('fs', 2));

        self::assertTrue($def->isSerializable());
    }

    // -------------------------------------------------------------------------
    // toDefinition / fromDefinition round-trip
    // -------------------------------------------------------------------------

    public function testSimpleFlowRoundTrips(): void
    {
        $def = FlowDefinition::define('intake')->create('fs');

        $data = $def->toDefinition();
        $restored = FlowDefinition::fromDefinition($data);

        self::assertSame('intake', $restored->name());
        self::assertTrue($restored->isSerializable());
    }

    /**
     * @psalm-suppress MixedArrayAccess
     */
    public function testFlowWithDimensionPriorityRoundTrips(): void
    {
        $def = FlowDefinition::define('reserve')
            ->move('fs', 'res')
            ->orderBy(new DimensionPriority(['chan' => ['web', 'api']]));

        $data = $def->toDefinition();

        self::assertSame('DimensionPriority', $data['steps'][0]['ordering'][0]['type']);
        self::assertSame(['web', 'api'], $data['steps'][0]['ordering'][0]['priorities']['chan']);

        $restored = FlowDefinition::fromDefinition($data);
        self::assertTrue($restored->isSerializable());
    }

    /**
     * @psalm-suppress MixedArrayAccess
     */
    public function testMinSlotTotalRoundTrips(): void
    {
        $def = FlowDefinition::define('reserve')
            ->move('fs', 'res')
            ->constraint(MinSlotTotal::define('fs', 5));

        $data = $def->toDefinition();

        self::assertSame('MinSlotTotal', $data['steps'][0]['constraints'][0]['type']);
        self::assertSame('fs', $data['steps'][0]['constraints'][0]['pattern']);
        self::assertSame(5, $data['steps'][0]['constraints'][0]['min']);

        $restored = FlowDefinition::fromDefinition($data);
        self::assertTrue($restored->isSerializable());
    }

    public function testMaxSlotTotalRoundTrips(): void
    {
        $constraint = MaxSlotTotal::define('res', 100);
        $data = $constraint->toDefinition();

        self::assertSame('MaxSlotTotal', $data['type']);

        $restored = MaxSlotTotal::fromDefinition($data);
        self::assertInstanceOf(MaxSlotTotal::class, $restored);
    }

    public function testMaxFlowQuantityRoundTrips(): void
    {
        $constraint = MaxFlowQuantity::define(50);
        $data = $constraint->toDefinition();

        self::assertSame('MaxFlowQuantity', $data['type']);
        self::assertSame(50, $data['max']);

        $restored = MaxFlowQuantity::fromDefinition($data);
        self::assertInstanceOf(MaxFlowQuantity::class, $restored);
    }

    public function testFromDefinitionWithUnknownTypeThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown policy type "NoSuchPolicy"');

        FlowDefinition::fromDefinition([
            'name'  => 'reserve',
            'steps' => [[
                'from'        => 'fs',
                'to'          => 'res',
                'ordering'    => [['type' => 'NoSuchPolicy']],
                'constraints' => [],
            ]],
        ]);
    }

    // -------------------------------------------------------------------------
    // DimensionPriority SerializablePolicy
    // -------------------------------------------------------------------------

    /**
     * @psalm-suppress MixedArrayAccess
     */
    public function testDimensionPriorityRoundTrips(): void
    {
        $policy = new DimensionPriority(['chan' => ['web', 'api'], 'loc' => ['wh1', 'wh2']]);
        $data = $policy->toDefinition();

        self::assertSame('DimensionPriority', $data['type']);
        self::assertSame(['web', 'api'], $data['priorities']['chan']);

        $restored = DimensionPriority::fromDefinition($data);
        self::assertInstanceOf(DimensionPriority::class, $restored);
    }

    // -------------------------------------------------------------------------
    // SlotSpaceDefinition::withFlow and toSlotSpace flow compilation
    // -------------------------------------------------------------------------

    public function testWithFlowRegistersFlowOnCompiledSpace(): void
    {
        $def = SlotSpaceDefinition::define('commercial', [
            DimensionDefinition::define('stt', ['fs', 'res']),
        ])->withFlow(
            FlowDefinition::define('reserve')->move('fs', 'res'),
        );

        $space = $def->toSlotSpace();

        self::assertArrayHasKey('reserve', $space->flows);
    }

    public function testWithFlowCompiledSpaceExecutesCorrectly(): void
    {
        $def = SlotSpaceDefinition::define('commercial', [
            DimensionDefinition::define('stt', ['fs', 'res']),
        ])->withFlow(
            FlowDefinition::define('reserve')->move('fs', 'res'),
        );

        $space = $def->toSlotSpace();
        $state = new QuantityState($space, [[$space->slot('fs'), 10]]);
        $engine = new \Nandan108\SlotFlow\MovementEngine();

        $result = $engine->execute($state, $space, 'reserve', 3);

        self::assertTrue($result->isComplete());
        // internal move: sum of all deltas must be 0 (nothing created or destroyed)
        $deltaSum = array_sum(array_map(static fn ($d) => $d->delta, $result->deltas()));
        self::assertEquals(0, $deltaSum);
    }

    public function testWithFlowRejectsDuplicateFlowName(): void
    {
        $this->expectException(\Nandan108\InvFlux\Exceptions\ConfigurationException::class);

        SlotSpaceDefinition::define('commercial', [
            DimensionDefinition::define('stt', ['fs', 'res']),
        ])
            ->withFlow(FlowDefinition::define('reserve')->move('fs', 'res'))
            ->withFlow(FlowDefinition::define('reserve')->move('res', 'fs'));
    }

    // -------------------------------------------------------------------------
    // LayeredSlotSpaceDefinition::withBoundaryFlow
    // -------------------------------------------------------------------------

    public function testWithBoundaryFlowAddsFlowsToEachLayer(): void
    {
        $def = \Nandan108\InvFlux\Layer\LayeredSlotSpaceDefinition::define([
            'commercial' => SlotSpaceDefinition::define('commercial', [
                DimensionDefinition::define('stt', ['fs', 'res']),
            ]),
            'physical' => SlotSpaceDefinition::define('physical', [
                DimensionDefinition::define('loc', ['wh']),
            ]),
        ])->withBoundaryFlow([
            'commercial' => FlowDefinition::define('intake')->create('fs'),
            'physical'   => FlowDefinition::define('intake')->create('wh'),
        ]);

        $spaces = $def->toSlotSpaces();

        self::assertArrayHasKey('intake', $spaces['commercial']->flows);
        self::assertArrayHasKey('intake', $spaces['physical']->flows);
    }

    public function testWithBoundaryFlowRejectsUnknownLayer(): void
    {
        $this->expectException(\Nandan108\InvFlux\Exceptions\ConfigurationException::class);
        $this->expectExceptionMessage('"nonexistent"');

        \Nandan108\InvFlux\Layer\LayeredSlotSpaceDefinition::define([
            'commercial' => SlotSpaceDefinition::define('commercial', [
                DimensionDefinition::define('stt', ['fs']),
            ]),
        ])->withBoundaryFlow([
            'nonexistent' => FlowDefinition::define('intake')->create('fs'),
        ]);
    }
}
