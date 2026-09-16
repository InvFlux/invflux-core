<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Tests\Domain\Subject;

use Nandan108\InvFlux\Domain\Subject\Subject;
use Nandan108\InvFlux\Domain\Subject\SubjectKind;
use Nandan108\InvFlux\Domain\Subject\SubjectTracking;
use PHPUnit\Framework\TestCase;

final class SubjectTrackingTest extends TestCase
{
    /** A base install never sees anything else, so the default must survive refactors. */
    public function testASubjectIsUntrackedByDefault(): void
    {
        $subject = new Subject();

        self::assertSame(SubjectTracking::None, $subject->tracking);
        self::assertSame(SubjectKind::Unit, $subject->kind);
        self::assertTrue($subject->kind->allowsTracking());
    }

    /**
     * The predicate that decides whether a Unit holds its own physical slots or delegates them
     * to Batch children — the read half of the (kind, tracking) residency rule.
     */
    public function testOnlyTheUntrackedModeKeepsThePhysicalLayer(): void
    {
        self::assertFalse(SubjectTracking::None->delegatesPhysicalLayer());
        self::assertTrue(SubjectTracking::Lot->delegatesPhysicalLayer());
        self::assertTrue(SubjectTracking::Serial->delegatesPhysicalLayer());
    }

    /** Per-piece identity is what caps a child at one unit; a lot child holds any quantity. */
    public function testOnlySerialIsPerPiece(): void
    {
        self::assertFalse(SubjectTracking::None->isPerPiece());
        self::assertFalse(SubjectTracking::Lot->isPerPiece());
        self::assertTrue(SubjectTracking::Serial->isPerPiece());
    }

    /** Values are persisted, so a rename is a data migration — pin them. */
    public function testBackedValuesAreStable(): void
    {
        self::assertSame(
            ['none', 'lot', 'serial'],
            array_map(static fn (SubjectTracking $t): string => $t->value, SubjectTracking::cases()),
        );
    }
}
