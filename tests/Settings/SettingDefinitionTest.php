<?php

declare(strict_types=1);

namespace Tests\Settings;

use Nandan108\InvFlux\Settings\InvalidSettingValue;
use Nandan108\InvFlux\Settings\ReplicationPolicy;
use Nandan108\InvFlux\Settings\SettingDefinition;
use PHPUnit\Framework\TestCase;

final class SettingDefinitionTest extends TestCase
{
    public function testUiFieldsDefaultToSubstrateNeutralValues(): void
    {
        $def = new SettingDefinition(
            name: 'federation.node_id',
            defaultValue: null,
            defaultPolicy: ReplicationPolicy::Local,
        );

        // A definition declared with only the substrate fields keeps working: the
        // UI fields fall back to neutral defaults (json control, no group/gate).
        $this->assertSame('json', $def->dataType);
        $this->assertSame([], $def->config);
        $this->assertNull($def->title);
        $this->assertNull($def->group);
        $this->assertSame(0, $def->order);
        $this->assertNull($def->featureKey);
        $this->assertNull($def->tier);
        $this->assertSame(['site'], $def->scopes);
        $this->assertFalse($def->hidden);
        $this->assertNull($def->validator);
    }

    public function testHiddenFlagRoundTrips(): void
    {
        $def = new SettingDefinition(
            name: 'federation.node_id',
            defaultValue: null,
            defaultPolicy: ReplicationPolicy::Local,
            policyLocked: true,
            hidden: true,
        );

        $this->assertTrue($def->hidden);
    }

    public function testUiFieldsRoundTrip(): void
    {
        $def = new SettingDefinition(
            name: 'dispatch.confirmation_window',
            defaultValue: 24,
            defaultPolicy: ReplicationPolicy::Local,
            policyLocked: false,
            description: 'Hours before an unconfirmed order is auto-released.',
            dataType: 'number',
            config: ['min' => 1, 'max' => 168],
            title: 'Confirmation window',
            group: 'Dispatch/Confirmation',
            order: 10,
            featureKey: 'dispatch.advanced-routing',
            tier: 'Pro',
            scopes: ['site', 'station'],
        );

        $this->assertSame('number', $def->dataType);
        $this->assertSame(['min' => 1, 'max' => 168], $def->config);
        $this->assertSame('Confirmation window', $def->title);
        $this->assertSame('Dispatch/Confirmation', $def->group);
        $this->assertSame(10, $def->order);
        $this->assertSame('dispatch.advanced-routing', $def->featureKey);
        $this->assertSame('Pro', $def->tier);
        $this->assertSame(['site', 'station'], $def->scopes);
    }

    public function testValidatorClosureIsStoredAndInvokable(): void
    {
        $validator = static fn (mixed $value): ?string => \is_int($value) && $value >= 1 && $value <= 0x0FFF
            ? null
            : 'Node ID must be an integer in 1–4095.';

        $def = new SettingDefinition(
            name: 'federation.node_id',
            defaultValue: null,
            defaultPolicy: ReplicationPolicy::Local,
            policyLocked: true,
            validator: $validator,
        );

        $this->assertNotNull($def->validator);
        $this->assertNull(($def->validator)(42));
        $this->assertSame('Node ID must be an integer in 1–4095.', ($def->validator)(0));
        $this->assertSame('Node ID must be an integer in 1–4095.', ($def->validator)('not-an-int'));
    }

    public function testInvalidSettingValueCarriesNameAndReason(): void
    {
        $e = new InvalidSettingValue('federation.node_id', 'Node ID must be an integer in 1–4095.');

        $this->assertSame('federation.node_id', $e->name);
        $this->assertSame('Node ID must be an integer in 1–4095.', $e->reason);
        $this->assertStringContainsString('federation.node_id', $e->getMessage());
        $this->assertStringContainsString('Node ID must be an integer', $e->getMessage());
    }
}
