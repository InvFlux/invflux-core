<?php

declare(strict_types=1);

namespace Tests\Schema;

use Nandan108\InvFlux\Schema\DimensionDefinition;
use Nandan108\InvFlux\Schema\DimensionValueDefinition;
use Nandan108\InvFlux\Schema\DimensionValueSelectorKind;
use Nandan108\InvFlux\Schema\SlotSpaceDefinition;
use Nandan108\InvFlux\Schema\SlotSpaceFactory;
use Nandan108\InvFlux\Schema\Stt;
use Nandan108\SlotFlow\MovementEngine;
use Nandan108\SlotFlow\QuantityState;
use PHPUnit\Framework\TestCase;

final class SlotSpaceFactoryTest extends TestCase
{
    /**
     * `stt` is a shared (DB-backed) dimension, open to add-on-contributed states — not a closed
     * native set baked into the definition. Its native values are seeded at bootstrap via
     * addDimensionValues(), exactly like `loc`.
     */
    public function testSttIsASharedRefDimensionOpenToAddonStates(): void
    {
        $commercial = (new SlotSpaceFactory())->createLayered()->layers[SlotSpaceFactory::LAYER_COMMERCIAL];

        $stt = null;
        foreach ($commercial->dimensions as $dim) {
            if ('stt' === $dim->name) {
                $stt = $dim;
                break;
            }
        }

        self::assertNotNull($stt, 'the commercial layer declares an stt dimension');
        self::assertTrue($stt->isSharedRef, 'stt is DB-backed/extensible, not a fixed native set');
        self::assertSame([], $stt->values, 'a sharedRef declares no inline values — the native set is seeded at bootstrap');
        self::assertSame(DimensionValueSelectorKind::Root, $stt->valueSelector?->kind, 'flat states → the root selector admits all');
    }

    /**
     * The location these flows run against. Any registered `loc` value would do — the cascades
     * under test are location-agnostic — so this names the seed rather than resolving one.
     */
    private const LOC = SlotSpaceFactory::DEFAULT_LOCATION_SEED;

    /** The `{location}` these parameterized flows bind to at execute time. */
    private const AT_LOC = [SlotSpaceFactory::PARAM_LOCATION => self::LOC];

    public function testWriteInWithoutADeficitCreatesStockInWarehouseForSale(): void
    {
        $space = $this->commercialSpace();
        $engine = new MovementEngine();

        // Goods receipt: write-in from nil into for-sale at the default warehouse. Goods
        // receipt has no dedicated flow — it IS a write-in, told apart by its movement type.
        $result = $engine->execute(new QuantityState($space), $space, SlotSpaceFactory::FLOW_WRITE_IN, 7, params: self::AT_LOC);

        self::assertTrue($result->isComplete());

        $deltas = $result->deltas();
        self::assertCount(1, $deltas, 'a write-in with no deficit produces a single create delta (atp)');
        self::assertSame(7, (int) $deltas[0]->delta, 'net +7 stock created');
        self::assertSame(Stt::ATP.'.'.SlotSpaceFactory::DEFAULT_LOCATION_SEED, $deltas[0]->slot->key);
    }

    /**
     * Write-in with a deficit backs confirmed commitments (ctd) FIRST, up to the deficit, then spills the
     * remainder into promisable stock (atp). The deficit arrives as an execute param.
     */
    public function testWriteInFlowBacksCommitmentDeficitBeforeAvailability(): void
    {
        $space = $this->commercialSpace();
        $engine = new MovementEngine();

        // 7 units received against a 4-unit backorder → 4 into ctd, 3 into atp.
        $result = $engine->execute(new QuantityState($space), $space, SlotSpaceFactory::FLOW_WRITE_IN, 7, params: self::AT_LOC + [SlotSpaceFactory::PARAM_DEFICIT => 4]);

        self::assertTrue($result->isComplete());
        self::assertSame(['ctd.oh' => 4, 'atp.oh' => 3], $this->byKey($result));
    }

    /** A deficit ≥ the received quantity sends everything to ctd (nothing promisable yet). */
    public function testWriteInFlowDeficitCapsAtReceivedQuantity(): void
    {
        $space = $this->commercialSpace();
        $engine = new MovementEngine();

        $result = $engine->execute(new QuantityState($space), $space, SlotSpaceFactory::FLOW_WRITE_IN, 5, params: self::AT_LOC + [SlotSpaceFactory::PARAM_DEFICIT => 10]);

        self::assertTrue($result->isComplete());
        self::assertSame(['ctd.oh' => 5], $this->byKey($result), 'all 5 back the deficit; none spill to atp');
    }

    /**
     * Write-off drains the weakest claim first: atp, then res, then ctd — protecting firm commitments to
     * the last. 10 out of {atp 5, res 3, ctd 4} = −5 atp, −3 res, −2 ctd (2 ctd survive).
     */
    public function testWriteOffFlowDrainsAtpThenResThenCtd(): void
    {
        $space = $this->commercialSpace();
        $engine = new MovementEngine();

        $state = new QuantityState($space);
        $state->setTuple([
            [['stt' => Stt::ATP, 'loc' => SlotSpaceFactory::DEFAULT_LOCATION_SEED], 5],
            [['stt' => Stt::RES, 'loc' => SlotSpaceFactory::DEFAULT_LOCATION_SEED], 3],
            [['stt' => Stt::CTD, 'loc' => SlotSpaceFactory::DEFAULT_LOCATION_SEED], 4],
        ]);

        $result = $engine->execute($state, $space, SlotSpaceFactory::FLOW_WRITE_OFF, 10, params: self::AT_LOC);

        self::assertTrue($result->isComplete());
        self::assertSame(['atp.oh' => -5, 'res.oh' => -3, 'ctd.oh' => -2], $this->byKey($result));
    }

    /** A write-off within free stock never touches res/ctd (no commitment breach). */
    public function testWriteOffFlowStaysInAtpWhenSufficient(): void
    {
        $space = $this->commercialSpace();
        $engine = new MovementEngine();

        $state = new QuantityState($space);
        $state->setTuple([
            [['stt' => Stt::ATP, 'loc' => SlotSpaceFactory::DEFAULT_LOCATION_SEED], 10],
            [['stt' => Stt::CTD, 'loc' => SlotSpaceFactory::DEFAULT_LOCATION_SEED], 4],
        ]);

        $result = $engine->execute($state, $space, SlotSpaceFactory::FLOW_WRITE_OFF, 4, params: self::AT_LOC);

        self::assertTrue($result->isComplete());
        self::assertSame(['atp.oh' => -4], $this->byKey($result), 'drawn from atp only; ctd untouched');
    }

    /**
     * The point of parameterizing these flows: they are **registered**, so anything that names a
     * flow — an event binding, a diagnostics snapshot — can reach them. As `Flow` builders taking a
     * location they were absent from the map, and so nameable by nothing.
     */
    public function testTheParameterizedFlowsAreRegisteredAndSoCanBeNamed(): void
    {
        $commercial = (new SlotSpaceFactory())->createLayered()->layers[SlotSpaceFactory::LAYER_COMMERCIAL];

        foreach ([
            SlotSpaceFactory::FLOW_WRITE_IN,
            SlotSpaceFactory::FLOW_WRITE_OFF,
            SlotSpaceFactory::FLOW_STOCK_ADJUST_ATP_ADD,
            SlotSpaceFactory::FLOW_STOCK_ADJUST_ATP_SUB,
            SlotSpaceFactory::FLOW_RECONCILE_ADD,
            SlotSpaceFactory::FLOW_RECONCILE_SUB,
        ] as $name) {
            self::assertArrayHasKey($name, $commercial->flows, "$name is registered on the commercial layer");
        }
    }

    /**
     * One definition, any warehouse. This is what the location parameter buys, and it is the reason
     * a receipt into a second warehouse needs no second flow.
     */
    public function testOneWriteInDefinitionServesEveryLocation(): void
    {
        $engine = new MovementEngine();

        foreach (['oh', 'annex'] as $loc) {
            $space = $this->commercialSpace($loc);
            $result = $engine->execute(
                new QuantityState($space),
                $space,
                SlotSpaceFactory::FLOW_WRITE_IN,
                3,
                params: [SlotSpaceFactory::PARAM_LOCATION => $loc],
            );

            self::assertTrue($result->isComplete());
            self::assertSame([Stt::ATP.'.'.$loc => 3], $this->byKey($result));
        }
    }

    /**
     * The engine refuses a pattern parameter nothing answered, so forgetting the location is a loud
     * failure at the call site rather than a flow that quietly matches no slot and moves nothing.
     */
    public function testAWriteInWithoutALocationIsRefused(): void
    {
        $space = $this->commercialSpace();

        $this->expectException(\Nandan108\SlotFlow\Exceptions\SlotFlowInvalidArgumentException::class);
        $this->expectExceptionMessage('needs parameter "location"');

        (new MovementEngine())->execute(new QuantityState($space), $space, SlotSpaceFactory::FLOW_WRITE_IN, 3);
    }

    /** The sign picks the direction; the caller passes the slot and location as params. */
    public function testReconciliationFlowNameSelectsByDirection(): void
    {
        $factory = new SlotSpaceFactory();

        self::assertSame(SlotSpaceFactory::FLOW_RECONCILE_ADD, $factory->reconciliationFlowName(Stt::ATP, 3));
        self::assertSame(SlotSpaceFactory::FLOW_RECONCILE_SUB, $factory->reconciliationFlowName(Stt::ATP, -3));
        self::assertSame(SlotSpaceFactory::FLOW_STOCK_ADJUST_ATP_ADD, $factory->stockAdjustAtpFlowName(1));
        self::assertSame(SlotSpaceFactory::FLOW_STOCK_ADJUST_ATP_SUB, $factory->stockAdjustAtpFlowName(-1));
    }

    /**
     * Native-only is a **regime** boundary, not a limitation of the pattern — inbound reconciliation
     * is only meaningful while the host's single stock number maps onto a simple native space. The
     * `{slot}` parameter would happily accept an add-on state, so the refusal has to be here.
     */
    public function testReconciliationRefusesANonNativeSlotAndAZeroDelta(): void
    {
        $factory = new SlotSpaceFactory();

        try {
            $factory->reconciliationFlowName('qi', 3);
            self::fail('a non-native slot must be refused');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('qi', $e->getMessage());
        }

        $this->expectException(\InvalidArgumentException::class);
        $factory->reconciliationFlowName(Stt::ATP, 0);
    }

    /** A zero-delta adjustment would append a ledger row recording no movement. */
    public function testStockAdjustRefusesAZeroDelta(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new SlotSpaceFactory())->stockAdjustAtpFlowName(0);
    }

    /**
     * A DB-less commercial space: the native states, and one location.
     *
     * This used to be `SlotSpaceFactory::commercialSlotSpace()`, and it is scaffolding rather than
     * API — which is why it now lives here. Hard-coding `Stt::NATIVE` is a *lie in production*: the
     * `stt` dimension is DB-backed precisely so add-ons can register `qi`, `bkd` and the `sup.*`
     * family, and a space built from the constant would silently omit them. Production callers ask
     * the store for its live layer schema instead. A unit test has no store, and this is the whole
     * of what it needs.
     */
    private function commercialSpace(string $loc = self::LOC): \Nandan108\SlotFlow\SlotSpace
    {
        $def = (new SlotSpaceFactory())->createLayered()->layers[SlotSpaceFactory::LAYER_COMMERCIAL];

        $dimensions = array_map(
            static function (DimensionDefinition $dim) use ($loc): DimensionDefinition {
                if (!$dim->isSharedRef) {
                    return $dim;
                }

                return 'stt' === $dim->name
                    ? $dim->withResolvedValues(
                        array_map(static fn (string $code): DimensionValueDefinition => new DimensionValueDefinition($code), Stt::NATIVE),
                        Stt::ATP,
                    )
                    : $dim->withResolvedValues([new DimensionValueDefinition($loc, level: 'warehouse')], $loc);
            },
            $def->dimensions,
        );

        return (new SlotSpaceDefinition($def->name, $dimensions, $def->metadata, $def->flows, $def->rules))->toSlotSpace();
    }

    /**
     * Collapse a movement result's deltas into a slot-key => net-delta map, for order-agnostic assertions.
     *
     * @return array<string, int>
     */
    private function byKey(\Nandan108\SlotFlow\MovementResult $result): array
    {
        $byKey = [];
        foreach ($result->deltas() as $d) {
            $byKey[$d->slot->key] = (int) $d->delta;
        }

        return $byKey;
    }
}
