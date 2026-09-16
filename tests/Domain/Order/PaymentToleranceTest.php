<?php

declare(strict_types=1);

namespace Tests\Domain\Order;

use Nandan108\InvFlux\Domain\Order\PaymentTolerance;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PaymentToleranceTest extends TestCase
{
    public function testExactAcceptsOnlyTheAmountOwed(): void
    {
        $exact = PaymentTolerance::exact();

        self::assertTrue($exact->accepts('120.50', '120.50'));
        self::assertFalse($exact->accepts('120.50', '120.49'));
        self::assertFalse($exact->accepts('120.50', '120.51'));
        self::assertSame('0.00', $exact->allowedFor('120.50'));
    }

    public function testAnAbsoluteLimitAcceptsEitherSide(): void
    {
        $tolerance = new PaymentTolerance('5.00');

        self::assertTrue($tolerance->accepts('100.00', '95.00'), 'short by the bank fee');
        self::assertTrue($tolerance->accepts('100.00', '105.00'));
        self::assertFalse($tolerance->accepts('100.00', '94.99'));
    }

    /**
     * @return iterable<string, array{string, string|null, string, string}>
     */
    public static function limits(): iterable
    {
        yield 'absolute alone' => ['5.00', null, '1000.00', '5.00'];
        yield 'percentage is smaller' => ['5.00', '0.2', '1000.00', '2.00'];
        yield 'absolute is smaller' => ['1.00', '2', '1000.00', '1.00'];
        yield 'percentage rounds down' => ['5.00', '1', '12.34', '0.12'];
        yield 'a zero absolute limit governs' => ['0', '2', '1000.00', '0.00'];
    }

    #[DataProvider('limits')]
    public function testTheSmallerLimitGoverns(string $absolute, ?string $percent, string $owed, string $allowed): void
    {
        self::assertSame($allowed, (new PaymentTolerance($absolute, $percent))->allowedFor($owed));
    }

    /**
     * @return iterable<string, array{string, string|null}>
     */
    public static function invalidLimits(): iterable
    {
        yield 'negative amount' => ['-1', null];
        yield 'not a number' => ['abc', null];
        yield 'negative percentage' => ['1', '-0.5'];
        yield 'over 100 %' => ['1', '101'];
    }

    #[DataProvider('invalidLimits')]
    public function testRejects(string $absolute, ?string $percent): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new PaymentTolerance($absolute, $percent);
    }
}
