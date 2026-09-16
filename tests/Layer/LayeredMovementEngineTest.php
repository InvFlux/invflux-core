<?php

declare(strict_types=1);

namespace Tests\Layer;

use Nandan108\InvFlux\Exceptions\ConfigurationException;
use Nandan108\InvFlux\Layer\BoundaryFlow;
use Nandan108\InvFlux\Layer\LayeredMovementEngine;
use Nandan108\InvFlux\Layer\LayeredQuantityState;
use Nandan108\InvFlux\Layer\LayeredSlotSpaceDefinition;
use Nandan108\InvFlux\Schema\DimensionDefinition;
use Nandan108\InvFlux\Schema\SlotSpaceDefinition;
use Nandan108\SlotFlow\QuantityState;
use Nandan108\SlotFlow\SlotSpace;
use PHPUnit\Framework\TestCase;

final class LayeredMovementEngineTest extends TestCase
{
    // -------------------------------------------------------------------------
    // Fixtures
    //
    // The engine accepts pre-compiled array<string, SlotSpace> with flows
    // already registered. buildSpaces() compiles once and registers flows so
    // the same instances are shared between the state and the engine call.
    // -------------------------------------------------------------------------

    /** @return array<string, SlotSpace> */
    private function buildSpaces(): array
    {
        $spaces = LayeredSlotSpaceDefinition::define([
            'commercial' => new SlotSpaceDefinition('commercial', [
                DimensionDefinition::define('stt', ['fs', 'res', 'sd']),
            ]),
            'physical' => new SlotSpaceDefinition('physical', [
                DimensionDefinition::define('loc', ['wh']),
            ]),
        ])->toSlotSpaces();

        // Commercial: intake adds to fs, dispatch removes from fs
        $spaces['commercial']->flow('intake', static fn ($f) => $f->create('fs'));
        $spaces['commercial']->flow('dispatch', static fn ($f) => $f->destroy('fs'));

        // Physical: intake adds to wh, dispatch removes from wh
        $spaces['physical']->flow('intake', static fn ($f) => $f->create('wh'));
        $spaces['physical']->flow('dispatch', static fn ($f) => $f->destroy('wh'));

        return $spaces;
    }

    /** @param array<string, SlotSpace> $spaces */
    private function buildState(array $spaces, int | float $commercialQty, int | float $physicalQty): LayeredQuantityState
    {
        return LayeredQuantityState::define([
            'commercial' => new QuantityState($spaces['commercial'], [
                [$spaces['commercial']->slot('fs'), $commercialQty],
            ]),
            'physical' => new QuantityState($spaces['physical'], [
                [$spaces['physical']->slot('wh'), $physicalQty],
            ]),
        ]);
    }

    private function intakeFlow(): BoundaryFlow
    {
        return BoundaryFlow::define(['commercial' => 'intake', 'physical' => 'intake']);
    }

    private function dispatchFlow(): BoundaryFlow
    {
        return BoundaryFlow::define(['commercial' => 'dispatch', 'physical' => 'dispatch']);
    }

    // -------------------------------------------------------------------------
    // Happy path
    // -------------------------------------------------------------------------

    public function testAllLayersSucceedReturnsOkTrue(): void
    {
        $spaces = $this->buildSpaces();
        $state = $this->buildState($spaces, 0, 0);
        $engine = new LayeredMovementEngine();

        $result = $engine->execute($state, $spaces, $this->intakeFlow(), 10);

        self::assertTrue($result->ok);
        self::assertArrayHasKey('commercial', $result->byLayer());
        self::assertArrayHasKey('physical', $result->byLayer());
        self::assertTrue($result->forLayer('commercial')->isComplete());
        self::assertTrue($result->forLayer('physical')->isComplete());
    }

    public function testCorrectDeltasProducedPerLayer(): void
    {
        $spaces = $this->buildSpaces();
        $state = $this->buildState($spaces, 0, 0);
        $engine = new LayeredMovementEngine();

        $result = $engine->execute($state, $spaces, $this->intakeFlow(), 5);

        $commercialDelta = $result->forLayer('commercial')->deltas()[0]->delta ?? null;
        $physicalDelta = $result->forLayer('physical')->deltas()[0]->delta ?? null;

        self::assertSame(5, $commercialDelta);
        self::assertSame(5, $physicalDelta);
    }

    public function testInvariantMaintainedAfterSuccessfulBoundaryExecution(): void
    {
        $spaces = $this->buildSpaces();
        $state = $this->buildState($spaces, 0, 0);
        $engine = new LayeredMovementEngine();

        $result = $engine->execute($state, $spaces, $this->intakeFlow(), 7);

        $sum = static fn (array $deltas): int | float => array_sum(array_map(
            static fn (\Nandan108\SlotFlow\Results\QuantityStateDelta $d): int | float => $d->delta,
            $deltas,
        ));

        self::assertSame(
            $sum($result->forLayer('commercial')->deltas()),
            $sum($result->forLayer('physical')->deltas()),
        );
    }

    // -------------------------------------------------------------------------
    // Partial failure
    // -------------------------------------------------------------------------

    public function testOneLayerWithRemainderReturnsOkFalse(): void
    {
        $spaces = $this->buildSpaces();
        // Physical has only 3 units; dispatch will have remainder of 7
        $state = $this->buildState($spaces, 100, 3);
        $engine = new LayeredMovementEngine();

        $result = $engine->execute($state, $spaces, $this->dispatchFlow(), 10);

        self::assertFalse($result->ok);
        self::assertSame(7, $result->forLayer('physical')->remaining);
        self::assertTrue($result->forLayer('commercial')->isComplete());
    }

    public function testRetryWithMaxFulfillableSucceeds(): void
    {
        $spaces = $this->buildSpaces();
        $state = $this->buildState($spaces, 3, 3);
        $engine = new LayeredMovementEngine();

        $failed = $engine->execute($state, $spaces, $this->dispatchFlow(), 10);
        self::assertFalse($failed->ok);

        $retryQty = $failed->maxFulfillable(10);
        self::assertSame(3, $retryQty);

        // SlotFlow never mutates state — rebuild for the retry
        $spaces2 = $this->buildSpaces();
        $retry = $this->buildState($spaces2, 3, 3);
        $result = $engine->execute($retry, $spaces2, $this->dispatchFlow(), $retryQty);

        self::assertTrue($result->ok);
    }

    // -------------------------------------------------------------------------
    // Configuration errors
    // -------------------------------------------------------------------------

    public function testEmptyBoundaryFlowThrows(): void
    {
        $this->expectException(ConfigurationException::class);
        BoundaryFlow::define([]);
    }

    public function testEmptyLayeredSlotSpaceDefinitionThrows(): void
    {
        $this->expectException(ConfigurationException::class);
        LayeredSlotSpaceDefinition::define([]);
    }

    public function testEmptySpacesArrayThrows(): void
    {
        $engine = new LayeredMovementEngine();
        $state = LayeredQuantityState::define([]);

        $this->expectException(ConfigurationException::class);
        $engine->execute($state, [], $this->intakeFlow(), 5);
    }

    public function testMissingFlowForLayerThrows(): void
    {
        $spaces = $this->buildSpaces();
        $state = $this->buildState($spaces, 0, 0);
        $engine = new LayeredMovementEngine();

        // BoundaryFlow only covers 'commercial', not 'physical'
        $incompleteFlow = BoundaryFlow::define(['commercial' => 'intake']);

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('physical');

        $engine->execute($state, $spaces, $incompleteFlow, 5);
    }

    public function testMissingQuantityStateForLayerThrows(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('commercial');

        LayeredQuantityState::define([])->forLayer('commercial');
    }
}
