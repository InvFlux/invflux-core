<?php

declare(strict_types=1);

namespace Tests\Util;

use Nandan108\InvFlux\Util\Wire;
use PHPUnit\Framework\TestCase;

final class WireTest extends TestCase
{
    public function testUtcIsoEmitsMillisecondPrecisionWithExplicitZ(): void
    {
        $dt = new \DateTimeImmutable('2026-07-29 14:03:22.123456', new \DateTimeZone('UTC'));

        $this->assertSame('2026-07-29T14:03:22.123Z', Wire::utcIso($dt));
    }

    /** The `Z` is the point: a non-UTC input must be converted, not relabelled. */
    public function testUtcIsoNormalisesNonUtcInput(): void
    {
        $dt = new \DateTimeImmutable('2026-07-29 16:03:22.000000', new \DateTimeZone('Europe/Zurich'));

        $this->assertSame('2026-07-29T14:03:22.000Z', Wire::utcIso($dt));
    }

    public function testUtcIsoPassesNullThrough(): void
    {
        $this->assertNull(Wire::utcIso(null));
    }

    public function testRowUtcIsoBridgesARawColumnToTheWire(): void
    {
        $this->assertSame(
            '2026-07-29T14:03:22.123Z',
            Wire::rowUtcIso(['occurred_at' => '2026-07-29 14:03:22.123456'], 'occurred_at'),
        );
        $this->assertSame(
            '2026-07-29T14:03:22.000Z',
            Wire::rowUtcIso(['occurred_at' => '2026-07-29 14:03:22'], 'occurred_at'),
        );
    }

    /** Presentational field — a missing or broken timestamp must not fail the whole response. */
    public function testRowUtcIsoYieldsNullRatherThanThrowing(): void
    {
        $this->assertNull(Wire::rowUtcIso([], 'occurred_at'));
        $this->assertNull(Wire::rowUtcIso(['occurred_at' => null], 'occurred_at'));
        $this->assertNull(Wire::rowUtcIso(['occurred_at' => 'nonsense'], 'occurred_at'));
    }
}
