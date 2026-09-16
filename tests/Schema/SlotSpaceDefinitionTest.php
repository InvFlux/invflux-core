<?php

declare(strict_types=1);

namespace Tests\Schema;

use Nandan108\InvFlux\Exceptions\ConfigurationException;
use Nandan108\InvFlux\Schema\DimensionDefinition;
use Nandan108\InvFlux\Schema\DimensionValueDefinition;
use Nandan108\InvFlux\Schema\DimensionValueSelector;
use Nandan108\InvFlux\Schema\SlotSpaceDefinition;
use PHPUnit\Framework\TestCase;

final class SlotSpaceDefinitionTest extends TestCase
{
    public function testRejectsEmptyName(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('Slot space name must be a non-empty string.');

        new SlotSpaceDefinition('', [DimensionDefinition::define('state', ['fs'])]);
    }

    public function testRejectsMissingDimensions(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('Slot space must contain at least one dimension.');

        new SlotSpaceDefinition('inventory', []);
    }

    public function testRejectsDuplicateDimensionNames(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('Duplicate dimension name "state" is not allowed.');

        new SlotSpaceDefinition('inventory', [
            DimensionDefinition::define('state', ['fs'], position: 0),
            DimensionDefinition::define('state', ['res'], position: 1),
        ]);
    }

    public function testRejectsDuplicateDimensionPositions(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('Duplicate dimension position "0" is not allowed.');

        new SlotSpaceDefinition('inventory', [
            DimensionDefinition::define('state', ['fs'], position: 0),
            DimensionDefinition::define('location', ['wh1'], position: 0),
        ]);
    }

    public function testActiveDimensionsAreSortedAndInactiveDimensionsAreExcluded(): void
    {
        $schema = new SlotSpaceDefinition('inventory', [
            DimensionDefinition::define('state', ['fs', 'res'], position: 2),
            DimensionDefinition::define('owner', ['cs', 'fp'], position: 1, active: false),
            DimensionDefinition::define('location', ['wh1', 'wh2'], position: 0),
        ]);

        $activeDimensions = $schema->activeDimensions();

        self::assertCount(2, $activeDimensions);
        self::assertSame(['location', 'state'], array_map(
            static fn (DimensionDefinition $dimension): string => $dimension->name,
            $activeDimensions,
        ));
    }

    public function testDimensionsAsArrayUsesNormalizedSortedShape(): void
    {
        $schema = new SlotSpaceDefinition('inventory', [
            DimensionDefinition::define('state', ['fs', 'res'], position: 1),
            DimensionDefinition::define('location', ['wh1', 'wh2'], position: 0),
        ]);

        self::assertSame([
            'location' => ['wh1', 'wh2'],
            'state'    => ['fs', 'res'],
        ], $schema->dimensionsAsArray());
    }

    public function testDimensionByNameReturnsDeclaredDimension(): void
    {
        $schema = new SlotSpaceDefinition('inventory', [
            DimensionDefinition::define('state', ['fs', 'res'], position: 1),
            DimensionDefinition::define('location', ['wh1', 'wh2'], position: 0),
        ]);

        $dimension = $schema->dimensionByName('location');
        self::assertInstanceOf(DimensionDefinition::class, $dimension);
        self::assertSame('location', $dimension->name);
    }

    public function testDimensionByNameReturnsNullForUnknownDimension(): void
    {
        $schema = new SlotSpaceDefinition('inventory', [
            DimensionDefinition::define('state', ['fs', 'res']),
        ]);

        self::assertNull($schema->dimensionByName('location'));
    }

    public function testToSlotSpacePreservesDeclaredDimensionOrder(): void
    {
        $schema = new SlotSpaceDefinition('inventory', [
            DimensionDefinition::define('state', ['fs', 'res'], position: 1),
            DimensionDefinition::define('location', ['wh1', 'wh2'], position: 0),
        ]);

        $slotSpace = $schema->toSlotSpace();

        self::assertSame(['location', 'state'], $slotSpace->dimensionNames());
        self::assertSame(['wh1', 'wh2'], $slotSpace->dimensionValues('location'));
        self::assertSame(['fs', 'res'], $slotSpace->dimensionValues('state'));
    }

    public function testToDefinitionSerializesDimensionValueCodeNameAndHierarchy(): void
    {
        $schema = new SlotSpaceDefinition('inventory', [
            new DimensionDefinition('location', 0, [
                new DimensionValueDefinition('wh1', name: 'Lausanne Warehouse', level: 'warehouse'),
                new DimensionValueDefinition('bin1', parentCode: 'wh1', level: 'bin'),
            ], 'wh1'),
        ]);

        $definition = $schema->toDefinition();
        self::assertIsArray($definition['dimensions']);
        $firstDimension = $definition['dimensions'][0] ?? null;
        self::assertIsArray($firstDimension);

        self::assertSame([
            [
                'code'    => 'wh1',
                'name'    => 'Lausanne Warehouse',
                'owner'   => 'core',
                'active'  => true,
                'level'   => 'warehouse',
            ],
            [
                'code'        => 'bin1',
                'owner'       => 'core',
                'active'      => true,
                'parent_code' => 'wh1',
                'level'       => 'bin',
            ],
        ], $firstDimension['values']);
    }

    public function testToDefinitionSerializesSharedRefSelector(): void
    {
        $schema = new SlotSpaceDefinition('inventory', [
            DimensionDefinition::sharedRef('loc', 0, DimensionValueSelector::leaf()),
        ]);

        $definition = $schema->toDefinition();
        self::assertIsArray($definition['dimensions']);
        $firstDimension = $definition['dimensions'][0] ?? null;
        self::assertIsArray($firstDimension);

        self::assertSame(
            ['kind' => 'leaf'],
            $firstDimension['valueSelector'],
        );
    }
}
