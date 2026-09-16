<?php

declare(strict_types=1);

namespace Tests\Domain\Procurement;

use Nandan108\InvFlux\Domain\Procurement\Supplier;
use PHPUnit\Framework\TestCase;

final class SupplierTest extends TestCase
{
    public function testDisplayNameUsesNicknameWhenSet(): void
    {
        $supplier = Supplier::newWith([
            'name'     => 'Northwest Optical Instruments & Trading GmbH',
            'nickname' => 'NW Optical',
        ]);

        self::assertSame('NW Optical', $supplier->displayName());
    }

    public function testDisplayNameFallsBackToNameWhenNicknameAbsentOrBlank(): void
    {
        self::assertSame(
            'Acme Trading',
            Supplier::newWith(['name' => 'Acme Trading'])->displayName(),
            'null nickname falls back to the full name',
        );
        self::assertSame(
            'Acme Trading',
            Supplier::newWith(['name' => 'Acme Trading', 'nickname' => '   '])->displayName(),
            'blank nickname falls back to the full name',
        );
    }
}
