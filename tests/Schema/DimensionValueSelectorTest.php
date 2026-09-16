<?php

declare(strict_types=1);

namespace Tests\Schema;

use Nandan108\InvFlux\Exceptions\ConfigurationException;
use Nandan108\InvFlux\Schema\DimensionValueSelector;
use Nandan108\InvFlux\Schema\DimensionValueSelectorKind;
use Nandan108\InvFlux\Schema\SharedDimensionValues;
use Nandan108\InvFlux\Schema\SlotSpaceFactory;
use PHPUnit\Framework\TestCase;

final class DimensionValueSelectorTest extends TestCase
{
    public function testLevelsSelectsSeveralKindsOfPlaceAtOneGrain(): void
    {
        $selector = DimensionValueSelector::levels(
            SlotSpaceFactory::LEVEL_WAREHOUSE,
            SharedDimensionValues::LEVEL_EXTERNAL,
        );

        self::assertSame(DimensionValueSelectorKind::Levels, $selector->kind);
        self::assertSame(['warehouse', 'external'], $selector->levels);
        self::assertNull($selector->level, 'the single-name field stays empty for a multi-name selector');
    }

    /**
     * One name *is* what `level()` means, so it collapses to that kind rather than becoming a
     * one-element list. Two ways of saying the same thing must not serialize differently, or a
     * re-declaration would read as a schema change.
     */
    public function testASingleNameCollapsesToTheLevelKind(): void
    {
        $selector = DimensionValueSelector::levels(SlotSpaceFactory::LEVEL_WAREHOUSE);

        self::assertSame(DimensionValueSelectorKind::Level, $selector->kind);
        self::assertSame('warehouse', $selector->level);
        self::assertSame([], $selector->levels);
        self::assertSame(
            DimensionValueSelector::level(SlotSpaceFactory::LEVEL_WAREHOUSE)->toArray(),
            $selector->toArray(),
            'the two spellings of one level produce one stored shape',
        );
    }

    public function testDuplicateNamesCollapse(): void
    {
        $selector = DimensionValueSelector::levels('warehouse', 'external', 'warehouse');

        self::assertSame(['warehouse', 'external'], $selector->levels);
    }

    public function testAllDuplicatesOfOneNameCollapseToLevel(): void
    {
        $selector = DimensionValueSelector::levels('warehouse', 'warehouse');

        self::assertSame(DimensionValueSelectorKind::Level, $selector->kind);
        self::assertSame('warehouse', $selector->level);
    }

    public function testAnEmptyNameIsRefused(): void
    {
        $this->expectException(ConfigurationException::class);

        DimensionValueSelector::levels('warehouse', '');
    }

    public function testNoNamesAtAllIsRefused(): void
    {
        $this->expectException(ConfigurationException::class);

        DimensionValueSelector::levels();
    }

    public function testLevelsSurvivesASerializationRoundTrip(): void
    {
        $selector = DimensionValueSelector::levels('warehouse', 'external');
        $restored = DimensionValueSelector::fromArray($selector->toArray());

        self::assertSame(DimensionValueSelectorKind::Levels, $restored->kind);
        self::assertSame(['warehouse', 'external'], $restored->levels);
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function malformedStoredSelectors(): iterable
    {
        yield 'levels missing entirely'   => [['kind' => 'levels']];
        yield 'levels not an array'       => [['kind' => 'levels', 'levels' => 'warehouse']];
        yield 'levels empty'              => [['kind' => 'levels', 'levels' => []]];
        yield 'levels holds no strings'   => [['kind' => 'levels', 'levels' => [1, null, false]]];
    }

    /**
     * A stored selector is data read back, not a literal, so a corrupt one must fail naming itself
     * rather than quietly selecting nothing — which would present as "the merchant's stock
     * vanished" with the schema looking intact.
     *
     * @param array<string, mixed> $stored
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('malformedStoredSelectors')]
    public function testAMalformedStoredLevelsSelectorIsRefusedRatherThanSelectingNothing(array $stored): void
    {
        $this->expectException(ConfigurationException::class);

        DimensionValueSelector::fromArray($stored);
    }

    public function testTheOtherKindsRoundTripUnchanged(): void
    {
        foreach ([DimensionValueSelector::root(), DimensionValueSelector::leaf()] as $selector) {
            $restored = DimensionValueSelector::fromArray($selector->toArray());

            self::assertSame($selector->kind, $restored->kind);
            self::assertSame([], $restored->levels, 'only a multi-name selector carries a level list');
        }
    }

    /**
     * The commercial layer must address warehouses *and* the places that are not warehouses —
     * supplier-side stock, transit legs, the customer's hands. Both level names, neither
     * mislabelled as the other.
     */
    public function testTheCommercialLayerAddressesWarehousesAndExternalCustody(): void
    {
        $commercial = (new SlotSpaceFactory())->createLayered()->layers[SlotSpaceFactory::LAYER_COMMERCIAL];

        $loc = null;
        foreach ($commercial->dimensions as $dim) {
            if ('loc' === $dim->name) {
                $loc = $dim;
                break;
            }
        }

        self::assertNotNull($loc);
        self::assertSame(DimensionValueSelectorKind::Levels, $loc->valueSelector?->kind);
        self::assertSame(
            [SlotSpaceFactory::LEVEL_WAREHOUSE, SharedDimensionValues::LEVEL_EXTERNAL],
            $loc->valueSelector?->levels,
        );
    }

    /**
     * The physical layer selects addressable leaves, and `physicalLayerSlug()` in the storage
     * package *identifies* that layer by exactly this — so widening the commercial selector must
     * not have made a second layer look leaf-selected.
     */
    public function testOnlyThePhysicalLayerUsesTheLeafSelector(): void
    {
        $layers = (new SlotSpaceFactory())->createLayered()->layers;

        $leafSelected = [];
        foreach ($layers as $slug => $layer) {
            foreach ($layer->dimensions as $dim) {
                if (DimensionValueSelectorKind::Leaf === $dim->valueSelector?->kind) {
                    $leafSelected[] = $slug;
                }
            }
        }

        self::assertSame([SlotSpaceFactory::LAYER_PHYSICAL], $leafSelected);
    }
}
