<?php

declare(strict_types=1);

namespace Tests\Settings;

use Nandan108\InvFlux\Settings\ReplicationPolicy;
use Nandan108\InvFlux\Settings\SettingDefinition;
use Nandan108\InvFlux\Settings\SettingsCatalog;
use PHPUnit\Framework\TestCase;

final class SettingsCatalogTest extends TestCase
{
    public function testEmptyCatalogConstructs(): void
    {
        $catalog = new SettingsCatalog([]);
        $this->assertSame([], $catalog->all());
        $this->assertFalse($catalog->has('anything'));
        $this->assertNull($catalog->find('anything'));
    }

    public function testHasFindGetForKnownKey(): void
    {
        $def = new SettingDefinition(
            name: 'federation.node_id',
            defaultValue: null,
            defaultPolicy: ReplicationPolicy::Local,
            policyLocked: true,
        );
        $catalog = new SettingsCatalog([$def]);

        $this->assertTrue($catalog->has('federation.node_id'));
        $this->assertSame($def, $catalog->find('federation.node_id'));
        $this->assertSame($def, $catalog->get('federation.node_id'));
        $this->assertSame([$def], $catalog->all());
    }

    public function testGetThrowsForUnknownKey(): void
    {
        $catalog = new SettingsCatalog([]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('no entry for "missing.key"');
        $catalog->get('missing.key');
    }

    public function testFindReturnsNullForUnknownKey(): void
    {
        $catalog = new SettingsCatalog([]);
        $this->assertNull($catalog->find('missing.key'));
    }

    public function testDuplicateNameRejectedAtConstruction(): void
    {
        $a = new SettingDefinition('dup.key', 1, ReplicationPolicy::Local);
        $b = new SettingDefinition('dup.key', 2, ReplicationPolicy::Replicated);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('duplicate name "dup.key"');
        new SettingsCatalog([$a, $b]);
    }

    public function testSettingDefinitionRejectsEmptyName(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('non-empty');
        new SettingDefinition('', null, ReplicationPolicy::Local);
    }

    public function testSettingDefinitionRejectsNameOver128Chars(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('max is 128');
        new SettingDefinition(str_repeat('a', 129), null, ReplicationPolicy::Local);
    }
}
