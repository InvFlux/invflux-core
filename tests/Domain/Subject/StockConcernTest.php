<?php

declare(strict_types=1);

namespace Tests\Domain\Subject;

use Nandan108\Attrecord\Exception\RecordValidationException;
use Nandan108\InvFlux\Domain\Subject\StockConcern;
use Nandan108\InvFlux\Domain\Subject\StockConcernBits;
use Nandan108\InvFlux\Domain\Subject\SubjectStockConcern;
use PHPUnit\Framework\TestCase;

final class StockConcernTest extends TestCase
{
    /**
     * StockConcernBits is the int-alias layer; the enum owns the values.
     *
     * This assertion is load-bearing rather than decorative. The constants used to read
     * `StockConcern::Case->value`, which made the correspondence a compile-time fact — but a
     * property fetch is not a valid constant expression before PHP 8.2, and this package supports
     * 8.1 for the PrestaShop adapter's host floor. The values are literals now, so this test is the
     * only thing standing between a renumbered enum case and a silently wrong SQL bit predicate.
     *
     * Exhaustive by construction: the map is compared against `cases()` as a whole, so adding a
     * case without adding its constant fails here rather than passing quietly.
     */
    public function testBitConstantsDeriveFromTheEnum(): void
    {
        $expected = [
            StockConcern::Deficit->name         => StockConcernBits::BIT_STOCK_DEFICIT,
            StockConcern::SubjectInactive->name => StockConcernBits::BIT_SUBJECT_INACTIVE,
            StockConcern::QualityHold->name     => StockConcernBits::BIT_QUALITY_HOLD,
            StockConcern::BatchExpired->name    => StockConcernBits::BIT_BATCH_EXPIRED,
            StockConcern::BatchExpiryRisk->name => StockConcernBits::BIT_BATCH_EXPIRY_RISK,
            StockConcern::LocAtRisk->name       => StockConcernBits::BIT_LOC_AT_RISK,
        ];

        $actual = [];
        foreach (StockConcern::cases() as $case) {
            $actual[$case->name] = $case->value;
        }

        self::assertSame($actual, $expected, 'StockConcernBits has drifted from StockConcern.');
        self::assertSame(
            StockConcern::mask(StockConcern::cases()),
            StockConcernBits::ALL_CORE_BITS,
        );
    }

    public function testMaskFoldsMembersOrderAndDuplicateIndependent(): void
    {
        self::assertSame(0, StockConcern::mask([]));
        self::assertSame(
            StockConcern::Deficit->value | StockConcern::SubjectInactive->value,
            StockConcern::mask([StockConcern::SubjectInactive, StockConcern::Deficit, StockConcern::Deficit]),
        );
    }

    public function testFromMaskDecomposesInDeclarationOrderAndIgnoresStaleBits(): void
    {
        self::assertSame([], StockConcern::fromMask(0));
        self::assertSame(
            [StockConcern::Deficit, StockConcern::QualityHold],
            StockConcern::fromMask(StockConcern::Deficit->value | StockConcern::QualityHold->value),
        );
        // A stale/foreign high bit (0x100, outside the defined bit space) is ignored; known bits still decode.
        self::assertSame(
            [StockConcern::SubjectInactive],
            StockConcern::fromMask(StockConcern::SubjectInactive->value | 0x100),
        );
    }

    public function testValidateRejectsAnEmptyConcernSet(): void
    {
        $row = new SubjectStockConcern();
        $row->subject_id = 42;
        $row->bits = [];

        $this->expectException(RecordValidationException::class);
        $row->validate();
    }

    public function testValidateAcceptsANonEmptyConcernSet(): void
    {
        $row = new SubjectStockConcern();
        $row->subject_id = 42;
        $row->bits = [StockConcern::Deficit];

        $row->validate();
        // No exception; validate() leaves the set intact.
        self::assertSame([StockConcern::Deficit], $row->bits);
    }
}
