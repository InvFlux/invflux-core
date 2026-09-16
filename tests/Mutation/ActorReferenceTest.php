<?php

declare(strict_types=1);

namespace Tests\Mutation;

use Nandan108\InvFlux\Mutation\ActorReference;
use PHPUnit\Framework\TestCase;

final class ActorReferenceTest extends TestCase
{
    public function testEqualsMatchesOnTypeCodeAndActorId(): void
    {
        $ref = new ActorReference('supplier', 'acme');

        self::assertTrue($ref->equals(new ActorReference('supplier', 'acme')));
        self::assertFalse($ref->equals(new ActorReference('supplier', 'other')));
        self::assertFalse($ref->equals(new ActorReference('customer', 'acme')));
    }

    public function testEqualsDistinguishesNullActorIdFromAValue(): void
    {
        self::assertTrue((new ActorReference('system'))->equals(new ActorReference('system')));
        self::assertFalse((new ActorReference('system'))->equals(new ActorReference('system', 'x')));
    }

    public function testEqualsIsFalseForNull(): void
    {
        self::assertFalse((new ActorReference('supplier', 'acme'))->equals(null));
    }
}
