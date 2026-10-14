<?php

declare(strict_types=1);

namespace Tests\Domain\Subject;

use Nandan108\InvFlux\Domain\Subject\IdentifierValidity;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * "Open-ended" must hold for the sentinel at whatever fractional-second precision a store keeps.
 *
 * A store that keeps microseconds returns the sentinel exactly; one that keeps none returns
 * `9999-12-31 23:59:59`, and an equality test against the sentinel then misses every live row
 * without an error. The floor is what every lookup compares against, so it must accept both forms
 * and reject every real expiry.
 */
final class IdentifierValidityTest extends TestCase
{
    /** @return iterable<string, array{string|\DateTimeInterface, bool}> */
    public static function values(): iterable
    {
        yield 'the sentinel, microseconds kept' => [IdentifierValidity::OPEN_ENDED, true];
        yield 'the sentinel, no fraction kept' => ['9999-12-31 23:59:59', true];
        yield 'the sentinel, milliseconds kept' => ['9999-12-31 23:59:59.999', true];
        yield 'the sentinel as an object' => [IdentifierValidity::openEnded(), true];
        yield 'a real expiry' => ['2026-10-10 14:00:00.000000', false];
        yield 'a real expiry as an object' => [new \DateTimeImmutable('2026-10-10 14:00:00'), false];
        yield 'the last real instant before the floor' => ['9999-12-30 23:59:59.999999', false];
    }

    #[DataProvider('values')]
    public function testItRecognisesTheOpenEndedSentinelAtAnyPrecision(string | \DateTimeInterface $validTo, bool $open): void
    {
        self::assertSame($open, IdentifierValidity::isOpenEnded($validTo));
    }

    public function testTheSentinelItselfSitsAtOrAboveTheFloor(): void
    {
        self::assertGreaterThanOrEqual(IdentifierValidity::OPEN_ENDED_FLOOR, IdentifierValidity::OPEN_ENDED);
    }
}
