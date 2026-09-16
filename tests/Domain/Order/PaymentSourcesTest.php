<?php

declare(strict_types=1);

namespace Tests\Domain\Order;

use Nandan108\InvFlux\Domain\Order\PaymentSources;
use Nandan108\InvFlux\Exceptions\ConfigurationException;
use PHPUnit\Framework\TestCase;

final class PaymentSourcesTest extends TestCase
{
    public function testKnowsTheBuiltInSources(): void
    {
        self::assertSame(['gateway', 'manual', 'host_status'], (new PaymentSources())->all());
    }

    public function testAnAddOnRegistersASourceOfItsOwn(): void
    {
        $sources = new PaymentSources();
        self::assertFalse($sources->has('carrier'));

        $sources->register('carrier');
        $sources->register('carrier');

        self::assertTrue($sources->has('carrier'));
        self::assertSame(['gateway', 'manual', 'host_status', 'carrier'], $sources->all(), 'registered once');
    }

    public function testRefusesACodeThatIsNotAnIdentifier(): void
    {
        $this->expectException(ConfigurationException::class);

        (new PaymentSources())->register('Cash on delivery');
    }

    public function testRefusesACodeWiderThanItsColumn(): void
    {
        $this->expectException(ConfigurationException::class);

        (new PaymentSources())->register(str_repeat('a', PaymentSources::MAX_LENGTH + 1));
    }
}
