<?php

declare(strict_types=1);

namespace Tests\Schema;

use Nandan108\InvFlux\Exceptions\ConfigurationException;
use Nandan108\InvFlux\Schema\CollapseBehavior;
use Nandan108\InvFlux\Schema\DimensionDefinition;
use Nandan108\InvFlux\Schema\DimensionValueDefinition;
use Nandan108\InvFlux\Schema\DimensionValueSelector;
use PHPUnit\Framework\TestCase;

final class DimensionDefinitionTest extends TestCase
{
    public function testDefineBuildsValidDimension(): void
    {
        $dimension = DimensionDefinition::define('state', ['fs', 'res', 'sd'], position: 2, defaultValue: 'res');

        self::assertSame('state', $dimension->name);
        self::assertSame(2, $dimension->position);
        self::assertSame('res', $dimension->defaultValue);
        self::assertSame(['fs', 'res', 'sd'], $dimension->valuesAsList());
    }

    public function testRejectsEmptyName(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('Dimension name must be a non-empty string.');

        DimensionDefinition::define('', ['fs']);
    }

    public function testRejectsNegativePosition(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('Dimension position must be >= 0.');

        DimensionDefinition::define('state', ['fs'], position: -1);
    }

    public function testRejectsEmptyValues(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('Dimension must define a default value.');

        DimensionDefinition::define('state', []);
    }

    public function testRejectsDuplicateValues(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('Dimension value codes must be unique within one dimension.');

        DimensionDefinition::define('state', ['fs', 'fs', 'res']);
    }

    public function testRejectsInvalidDefaultValue(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('Dimension default value "other" must be one of the declared value codes.');

        DimensionDefinition::define('state', ['fs', 'res'], defaultValue: 'other');
    }

    public function testRejectsMissingMapToValueTarget(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('collapseTargetValue is required for map_to_value dimensions.');

        DimensionDefinition::define(
            'state',
            ['fs', 'res'],
            collapseBehavior: CollapseBehavior::MapToValue,
        );
    }

    public function testRejectsUnknownMapToValueTarget(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('collapseTargetValue "other" must be one of the declared dimension values.');

        DimensionDefinition::define(
            'state',
            ['fs', 'res'],
            collapseBehavior: CollapseBehavior::MapToValue,
            collapseTargetValue: 'other',
        );
    }

    public function testValuesAsListExcludesInactiveLegacyValues(): void
    {
        $dimension = new DimensionDefinition(
            'state',
            0,
            [
                new DimensionValueDefinition('fs'),
                new DimensionValueDefinition('res'),
                new DimensionValueDefinition('ret', active: false),
            ],
            'fs',
        );

        self::assertSame(['fs', 'res'], $dimension->valuesAsList());
        self::assertSame(['fs', 'res', 'ret'], $dimension->allValuesAsList());
    }

    public function testValuesByCodeReturnsActiveValueMapByDefault(): void
    {
        $dimension = new DimensionDefinition(
            'state',
            0,
            [
                new DimensionValueDefinition('fs'),
                new DimensionValueDefinition('res'),
                new DimensionValueDefinition('ret', active: false),
            ],
            'fs',
        );

        self::assertSame(['fs', 'res'], array_keys($dimension->valuesByCode()));
        $value = $dimension->valuesByCode('res');
        self::assertInstanceOf(DimensionValueDefinition::class, $value);
        self::assertSame('res', $value->code);
    }

    public function testValuesByCodeCanIncludeInactiveValues(): void
    {
        $dimension = new DimensionDefinition(
            'state',
            0,
            [
                new DimensionValueDefinition('fs'),
                new DimensionValueDefinition('ret', active: false),
            ],
            'fs',
        );

        self::assertSame(['fs', 'ret'], array_keys($dimension->valuesByCode(includeInactive: true)));
        $value = $dimension->valuesByCode('ret', includeInactive: true);
        self::assertInstanceOf(DimensionValueDefinition::class, $value);
        self::assertSame('ret', $value->code);
    }

    public function testValuesByCodeReturnsNullForUnknownOrInactiveCodes(): void
    {
        $dimension = new DimensionDefinition(
            'state',
            0,
            [
                new DimensionValueDefinition('fs'),
                new DimensionValueDefinition('ret', active: false),
            ],
            'fs',
        );

        self::assertNull($dimension->valuesByCode('ret'));
        self::assertNull($dimension->valuesByCode('missing'));
    }

    public function testRejectsInvalidValueRemovalTarget(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('removalTargetCode "other" must be one of the declared dimension value codes.');

        new DimensionDefinition(
            'state',
            0,
            [
                new DimensionValueDefinition('fs'),
                new DimensionValueDefinition(
                    'ret',
                    removalTargetCode: 'other',
                ),
            ],
            'fs',
        );
    }

    public function testRejectsSelfMappedRemovalTarget(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('cannot map to itself');

        new DimensionDefinition(
            'state',
            0,
            [
                new DimensionValueDefinition('fs'),
                new DimensionValueDefinition('ret', removalTargetCode: 'ret'),
            ],
            'fs',
        );
    }

    public function testSharedRefRequiresExplicitSelector(): void
    {
        $this->expectException(\ArgumentCountError::class);

        /** @psalm-suppress TooFewArguments */
        DimensionDefinition::sharedRef('loc', 1);
    }

    public function testSharedRefStoresSelector(): void
    {
        $dimension = DimensionDefinition::sharedRef('loc', 1, DimensionValueSelector::level('warehouse'));

        self::assertTrue($dimension->isSharedRef);
        self::assertSame(1, $dimension->position);
        self::assertSame(DimensionValueSelector::level('warehouse')->toArray(), $dimension->valueSelector?->toArray());
    }

    public function testDimensionValueUsesCodeNameAndNoPosition(): void
    {
        $value = new DimensionValueDefinition('wh1', name: 'Lausanne Warehouse', level: 'warehouse');

        self::assertSame('wh1', $value->code);
        self::assertSame('Lausanne Warehouse', $value->name);
        self::assertSame('warehouse', $value->level);
        self::assertFalse(property_exists($value, 'position'));
    }
}
