<?php

declare(strict_types=1);

namespace Tests\Domain\Procurement;

use Nandan108\InvFlux\Domain\Procurement\PoStatus;
use PHPUnit\Framework\TestCase;

final class PoStatusTest extends TestCase
{
    public function testEveryCaseHasADistinctSlug(): void
    {
        $slugs = array_map(static fn (PoStatus $s): string => $s->slug(), PoStatus::cases());

        self::assertCount(count(PoStatus::cases()), array_unique($slugs), 'slugs must be unique');
        self::assertNotContains('', $slugs, 'no empty slug');
    }

    public function testSlugRoundTrips(): void
    {
        foreach (PoStatus::cases() as $case) {
            self::assertSame($case, PoStatus::fromSlug($case->slug()));
        }
    }

    public function testFromSlugKnownValues(): void
    {
        self::assertSame(PoStatus::Submitted, PoStatus::fromSlug('submitted'));
        // Keyed on the case, not a literal: the backing ints are renumberable by design.
        self::assertSame(PoStatus::Submitted->value, PoStatus::fromSlug('submitted')?->value, 'slug resolves to the backing int');
        self::assertSame(PoStatus::PartiallyReceived, PoStatus::fromSlug('partially_received'));
    }

    /**
     * The backing ints are odd by design so every even value stays free for a future intermediate
     * state — an install carrying extra states still agrees with a base install on what each int means.
     */
    public function testEveryBackingValueIsOdd(): void
    {
        foreach (PoStatus::cases() as $case) {
            self::assertSame(1, $case->value % 2, $case->name.' must carry an odd backing value');
        }
    }

    public function testFromSlugRejectsUnknown(): void
    {
        self::assertNull(PoStatus::fromSlug('bogus_stage'));
        self::assertNull(PoStatus::fromSlug(''));
    }

    /**
     * The "on order" set — what a merchant is shown as inbound.
     *
     * Named case by case rather than as a list, because the two that were historically missing
     * (`acknowledged`, `partially_received`) went missing precisely because a hand-written list
     * looked plausible without them.
     */
    public function testTheOrderIsWithTheSupplierAndNotFinishedArriving(): void
    {
        foreach ([
            PoStatus::Submitted,
            PoStatus::Acknowledged,
            PoStatus::InTransit,
            PoStatus::InReception,
            PoStatus::PartiallyReceived,
        ] as $open) {
            self::assertTrue($open->isOpenForInbound(), $open->name.' is stock on the way');
        }
    }

    public function testNothingBeforeSubmissionCounts(): void
    {
        // The supplier has not been told, so there is no stock coming however complete the paperwork.
        foreach ([PoStatus::InPrep, PoStatus::InReview, PoStatus::Approved] as $ours) {
            self::assertFalse($ours->isOpenForInbound(), $ours->name.' is not yet with the supplier');
        }
    }

    public function testNothingFinishedOrAbandonedCounts(): void
    {
        // Received needs no special handling for *quantity* (qty_open is 0), but a cancelled PO
        // keeps a real qty_open forever — so excluding it by status is load-bearing.
        foreach ([PoStatus::Received, PoStatus::Cancelled] as $done) {
            self::assertFalse($done->isOpenForInbound(), $done->name.' is not stock on the way');
        }
    }

    /**
     * The predicate every "what is on the way" query should use, rather than the status list alone.
     *
     * Both halves are asserted because the archived clause is the one that goes missing: a
     * `status IN (…)` list reads as a complete answer at the call site, and nothing about it says a
     * second condition is owed. Filing an order away is not a status, so the enum cannot carry it.
     */
    public function testTheInboundPredicateExcludesFiledAwayOrders(): void
    {
        $sql = PoStatus::openForInboundSql('po');

        self::assertStringContainsString('po.archived_at IS NULL', $sql);
        self::assertStringContainsString('po.status IN (', $sql);
        foreach (PoStatus::openForInboundValues() as $open) {
            self::assertStringContainsString((string) $open, $sql);
        }
    }

    public function testAcknowledgedIsNeverStricterThanSubmitted(): void
    {
        // Acknowledged is the supplier having confirmed the very order Submitted only sent. A set
        // holding one and not the other counts the less certain state and drops the more certain.
        self::assertSame(
            PoStatus::Submitted->isOpenForInbound(),
            PoStatus::Acknowledged->isOpenForInbound(),
        );
    }

    public function testTheSqlValueListMatchesThePredicate(): void
    {
        // The list exists so a raw-SQL `IN (…)` never restates the membership; it must therefore be
        // derived from the predicate rather than maintained beside it.
        $expected = array_values(array_map(
            static fn (PoStatus $s): int => $s->value,
            array_filter(PoStatus::cases(), static fn (PoStatus $s): bool => $s->isOpenForInbound()),
        ));

        self::assertSame($expected, PoStatus::openForInboundValues());
        self::assertNotContains(PoStatus::Received->value, PoStatus::openForInboundValues());
        self::assertContains(PoStatus::PartiallyReceived->value, PoStatus::openForInboundValues());
    }

    public function testEveryCaseIsClassified(): void
    {
        // `isOpenForInbound` matches exhaustively, so a status added without a decision is a fatal
        // rather than a silent `false` — this asserts the match stays total as cases are added.
        foreach (PoStatus::cases() as $case) {
            $case->isOpenForInbound();
        }

        self::assertCount(count(PoStatus::cases()), PoStatus::cases());
    }
}
