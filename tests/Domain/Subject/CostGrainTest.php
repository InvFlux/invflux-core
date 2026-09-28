<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Tests\Domain\Subject;

use Nandan108\InvFlux\Domain\Subject\CostGrain;
use Nandan108\InvFlux\Domain\Subject\Subject;
use Nandan108\InvFlux\Domain\Subject\SubjectTracking;
use PHPUnit\Framework\TestCase;

final class CostGrainTest extends TestCase
{
    /**
     * Every row a base install creates carries this, and nothing reads the column until cost
     * layers and lot identity both exist — so the default is the whole of its correctness today.
     */
    public function testASubjectCostsAtUnitGrainByDefault(): void
    {
        $subject = new Subject();

        self::assertSame(CostGrain::Unit, $subject->cost_grain);
    }

    /** The predicate that decides whether a layer hangs off the unit or off its Batch children. */
    public function testOnlyBatchGrainDelegatesToChildren(): void
    {
        self::assertFalse(CostGrain::Unit->delegatesToChildren());
        self::assertTrue(CostGrain::Batch->delegatesToChildren());
    }

    /**
     * The dependency runs costing → tracking and only that way: batch grain needs Batch children
     * to hang layers off, which only a tracked subject has, while a tracked subject on a single
     * rolling average is an ordinary arrangement rather than a contradiction. The database half
     * of this is the `cost_grain_needs_tracking` CHECK on {@see Subject}.
     */
    public function testBatchGrainIsTheHalfThatDependsOnTracking(): void
    {
        $default = new Subject();

        // The combination the CHECK exists to reject is reachable in PHP — the constraint is the
        // database's answer, not the type system's, which is why it is worth having.
        self::assertSame(SubjectTracking::None, $default->tracking);
        self::assertTrue(CostGrain::Batch->delegatesToChildren());

        // ...while the converse combination is legitimate and must never be refused.
        self::assertFalse(CostGrain::Unit->delegatesToChildren());
    }

    /** Values are persisted, so a rename is a data migration — pin them. */
    public function testBackedValuesAreStable(): void
    {
        self::assertSame(
            ['unit', 'batch'],
            array_map(static fn (CostGrain $g): string => $g->value, CostGrain::cases()),
        );
    }
}
