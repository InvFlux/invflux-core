<?php

declare(strict_types=1);

namespace Tests\Util;

use Nandan108\InvFlux\Util\Row;
use PHPUnit\Framework\TestCase;

final class RowTest extends TestCase
{
    // ── str ────────────────────────────────────────────────────────────────────

    public function testStrReadsScalarColumns(): void
    {
        $row = ['name' => 'Widget', 'qty' => 3, 'ok' => true, 'price' => 1.5];

        $this->assertSame('Widget', Row::str($row, 'name'));
        $this->assertSame('3', Row::str($row, 'qty'));
        $this->assertSame('1', Row::str($row, 'ok'));
        $this->assertSame('1.5', Row::str($row, 'price'));
    }

    /** An empty VARCHAR is data — `str()` hands it back and a strict read does not throw. */
    public function testStrTreatsEmptyStringAsData(): void
    {
        $this->assertSame('', Row::str(['sku' => ''], 'sku'));
        $this->assertSame('', Row::str(['sku' => ''], 'sku', null));
    }

    public function testStrFallsBackToDefaultWhenUncoercible(): void
    {
        $this->assertSame('n/a', Row::str([], 'name', 'n/a'));
        $this->assertNull(Row::str([], 'name', null));
        $this->assertNull(Row::str(['name' => null], 'name', null));
        $this->assertNull(Row::str(['name' => ['a']], 'name', null));
    }

    public function testStrThrowsOnAbsentColumnWhenStrict(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Row column "name" is absent');

        Row::str(['other' => 'x'], 'name');
    }

    /** Present-but-wrong-shape reports differently from absent — different bug, different hunt. */
    public function testStrThrowsDistinctlyOnWrongShapeWhenStrict(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Row column "name" is not a scalar.');

        Row::str(['name' => ['nested']], 'name');
    }

    public function testStrAbsenceMessageListsAvailableColumns(): void
    {
        $this->expectExceptionMessage('row has: id, sku');

        Row::str(['id' => 1, 'sku' => 'A'], 'name');
    }

    // ── nonEmptyStr ────────────────────────────────────────────────────────────

    public function testNonEmptyStrCollapsesEmptyStringToDefault(): void
    {
        $this->assertNull(Row::nonEmptyStr(['sku' => ''], 'sku', null));
        $this->assertSame('none', Row::nonEmptyStr(['sku' => ''], 'sku', 'none'));
        $this->assertSame('A1', Row::nonEmptyStr(['sku' => 'A1'], 'sku', null));
    }

    public function testNonEmptyStrTreatsAbsentAndNullAlike(): void
    {
        $this->assertNull(Row::nonEmptyStr([], 'sku', null));
        $this->assertNull(Row::nonEmptyStr(['sku' => null], 'sku', null));
    }

    public function testNonEmptyStrThrowsOnEmptyStringWhenStrict(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Row column "sku" is not a non-empty scalar.');

        Row::nonEmptyStr(['sku' => ''], 'sku');
    }

    // ── int ────────────────────────────────────────────────────────────────────

    public function testIntReadsNumericColumns(): void
    {
        $this->assertSame(7, Row::int(['qty' => 7], 'qty'));
        $this->assertSame(7, Row::int(['qty' => '7'], 'qty'));
        $this->assertSame(7, Row::int(['qty' => '7.9'], 'qty'));
        $this->assertSame(0, Row::int(['qty' => '0'], 'qty'));
    }

    /** A non-numeric column must not silently become `0` — `0` is a plausible quantity. */
    public function testIntFallsBackRatherThanCastingNonNumerics(): void
    {
        $this->assertNull(Row::int(['qty' => 'abc'], 'qty', null));
        $this->assertSame(-1, Row::int(['qty' => 'abc'], 'qty', -1));
        $this->assertNull(Row::int(['qty' => ''], 'qty', null));
        $this->assertSame(0, Row::int([], 'qty', 0));
    }

    public function testIntThrowsOnNonNumericWhenStrict(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Row column "qty" is not numeric.');

        Row::int(['qty' => 'abc'], 'qty');
    }

    // ── decimal ────────────────────────────────────────────────────────────────

    /** DECIMAL stays a string: the exact value survives, rounding stays the caller's call. */
    public function testDecimalPreservesTheDriverString(): void
    {
        $this->assertSame('19.99', Row::decimal(['amount' => '19.99'], 'amount'));
        $this->assertSame('0.000000001', Row::decimal(['amount' => '0.000000001'], 'amount'));
        $this->assertSame('12', Row::decimal(['amount' => 12], 'amount'));
    }

    public function testDecimalFallsBackWhenUncoercible(): void
    {
        $this->assertNull(Row::decimal(['amount' => null], 'amount', null));
        $this->assertSame('0', Row::decimal([], 'amount', '0'));
    }

    public function testDecimalThrowsOnNonNumericWhenStrict(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Row column "amount" is not numeric.');

        Row::decimal(['amount' => 'free'], 'amount');
    }

    // ── datetime ───────────────────────────────────────────────────────────────

    public function testDatetimeParsesBothStoredPrecisions(): void
    {
        $micro = Row::datetime(['ts' => '2026-07-29 14:03:22.123456'], 'ts');
        $this->assertSame('2026-07-29 14:03:22.123456', $micro->format('Y-m-d H:i:s.u'));
        $this->assertSame('UTC', $micro->getTimezone()->getName());

        $second = Row::datetime(['ts' => '2026-07-29 14:03:22'], 'ts');
        $this->assertSame('2026-07-29 14:03:22.000000', $second->format('Y-m-d H:i:s.u'));
        $this->assertSame('UTC', $second->getTimezone()->getName());
    }

    public function testDatetimeFallsBackOnUnparseableValues(): void
    {
        $this->assertNull(Row::datetime(['ts' => ''], 'ts', null));
        $this->assertNull(Row::datetime(['ts' => null], 'ts', null));
        $this->assertNull(Row::datetime(['ts' => 'not a date'], 'ts', null));
        $this->assertNull(Row::datetime(['ts' => 1234567890], 'ts', null));
        $this->assertNull(Row::datetime([], 'ts', null));

        $fallback = new \DateTimeImmutable('2000-01-01 00:00:00', new \DateTimeZone('UTC'));
        $this->assertSame($fallback, Row::datetime([], 'ts', $fallback));
    }

    public function testDatetimeThrowsOnUnparseableWhenStrict(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Row column "ts" is not a UTC datetime string.');

        Row::datetime(['ts' => 'not a date'], 'ts');
    }

    // ── hexId ──────────────────────────────────────────────────────────────────

    public function testHexIdEncodesBinaryColumns(): void
    {
        $binary = hex2bin('0198a1f2c3d44e5f8091a2b3c4d5e6f7');
        $this->assertSame('0198a1f2c3d44e5f8091a2b3c4d5e6f7', Row::hexId(['id' => $binary], 'id'));
    }

    /** The whole point of strict-by-default here: `bin2hex('')` is a valid-looking empty id. */
    public function testHexIdThrowsRatherThanEmitAnEmptyId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Row column "id" is not a non-empty binary string.');

        Row::hexId(['id' => ''], 'id');
    }

    public function testHexIdFallsBackWhenDefaulted(): void
    {
        $this->assertNull(Row::hexId(['id' => ''], 'id', null));
        $this->assertNull(Row::hexId([], 'id', null));
    }
}
