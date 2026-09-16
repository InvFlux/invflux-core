<?php

declare(strict_types=1);

namespace Tests\Domain\Subject;

use Nandan108\InvFlux\Domain\Subject\SubjectKind;
use PHPUnit\Framework\TestCase;

/**
 * Two independent axes run through {@see SubjectKind}, and the whole point of asking the enum
 * rather than the case is that they cross:
 *
 *  - `ownsSlots()` — does this row hold inventory (the domain question)
 *  - `isVariantLevel()` — where does it sit in the FK hierarchy (the storage question)
 *
 * `Kit` and `NonStocked` are variant-level *and* slot-less. Code that reached for one axis while
 * meaning the other classifies both backwards, which is exactly the mistake these tests pin.
 */
final class SubjectKindTest extends TestCase
{
    public function testOnlyUnitAndBatchOwnSlots(): void
    {
        self::assertTrue(SubjectKind::Unit->ownsSlots());
        self::assertTrue(SubjectKind::Batch->ownsSlots(), 'Batch holds physical-layer inventory.');

        self::assertFalse(SubjectKind::Aggregate->ownsSlots(), 'An aggregate sums its children.');
        self::assertFalse(SubjectKind::Kit->ownsSlots(), 'A kit derives from its components.');
        self::assertFalse(SubjectKind::NonStocked->ownsSlots(), 'A non-stocked item has no quantity at all.');
    }

    public function testVariantLevelIsAboutHierarchyNotStock(): void
    {
        self::assertTrue(SubjectKind::Unit->isVariantLevel());
        self::assertTrue(SubjectKind::Kit->isVariantLevel());
        self::assertTrue(SubjectKind::NonStocked->isVariantLevel());

        self::assertFalse(SubjectKind::Aggregate->isVariantLevel(), 'An aggregate is product-level.');
        self::assertFalse(SubjectKind::Batch->isVariantLevel(), 'A batch sits below the variant.');
    }

    /** The axes are independent: two kinds are variant-level while owning no slots. */
    public function testTheTwoAxesCross(): void
    {
        foreach ([SubjectKind::Kit, SubjectKind::NonStocked] as $kind) {
            self::assertTrue($kind->isVariantLevel(), $kind->value.' is variant-level');
            self::assertFalse($kind->ownsSlots(), $kind->value.' owns no slots');
        }
    }

    public function testVariantLevelKindsMayOnlyHangUnderAnAggregate(): void
    {
        foreach ([SubjectKind::Unit, SubjectKind::Kit, SubjectKind::NonStocked] as $kind) {
            self::assertTrue($kind->canBeChildOf(SubjectKind::Aggregate), $kind->value.' under aggregate');
            self::assertFalse($kind->canBeChildOf(SubjectKind::Unit), $kind->value.' under unit');
            self::assertFalse($kind->canBeChildOf(SubjectKind::Batch), $kind->value.' under batch');
            self::assertFalse($kind->canBeChildOf(SubjectKind::Kit), $kind->value.' under kit');
        }
    }

    public function testBatchMayOnlyHangUnderAUnit(): void
    {
        self::assertTrue(SubjectKind::Batch->canBeChildOf(SubjectKind::Unit));
        self::assertFalse(SubjectKind::Batch->canBeChildOf(SubjectKind::Aggregate));
        self::assertFalse(SubjectKind::Batch->canBeChildOf(SubjectKind::Kit));
        self::assertFalse(
            SubjectKind::Batch->canBeChildOf(SubjectKind::NonStocked),
            'A non-stocked item has no quantity to break into lots.',
        );
    }

    public function testAnAggregateIsNeverAChild(): void
    {
        foreach (SubjectKind::cases() as $parent) {
            self::assertFalse(
                SubjectKind::Aggregate->canBeChildOf($parent),
                'aggregate under '.$parent->value,
            );
        }
    }

    /**
     * Tracking delegates the physical layer to child subjects, so only the kind that *holds* a
     * physical layer it could delegate may carry it. A Batch answering true would recurse
     * (a delegate delegating); a slot-less kind has nothing to identify.
     */
    public function testOnlyAUnitAllowsTracking(): void
    {
        foreach (SubjectKind::cases() as $kind) {
            self::assertSame(
                SubjectKind::Unit === $kind,
                $kind->allowsTracking(),
                $kind->value.' allowsTracking()',
            );
        }
    }

    /**
     * The identity predicates stay exhaustive as cases are added — a `NonStocked` that answered
     * `isUnit()` would quietly acquire unit behaviour everywhere the old predicates are still used.
     */
    public function testIdentityPredicatesAreMutuallyExclusive(): void
    {
        foreach (SubjectKind::cases() as $kind) {
            $matches = array_filter([
                $kind->isAggregate(),
                $kind->isUnit(),
                $kind->isKit(),
                $kind->isBatch(),
                $kind->isNonStocked(),
            ]);
            self::assertCount(1, $matches, $kind->value.' matches exactly one identity predicate');
        }
    }
}
