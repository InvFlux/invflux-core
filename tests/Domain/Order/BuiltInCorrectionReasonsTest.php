<?php

declare(strict_types=1);

namespace Tests\Domain\Order;

use Nandan108\InvFlux\Domain\Order\BuiltInCorrectionReasons;
use Nandan108\InvFlux\Domain\Order\Cause;
use Nandan108\InvFlux\Domain\Order\CorrectionTiming;
use Nandan108\InvFlux\Domain\Order\OrderCorrectionReason;
use PHPUnit\Framework\TestCase;

final class BuiltInCorrectionReasonsTest extends TestCase
{
    public function testReturnsCanonicalReasons(): void
    {
        $reasons = BuiltInCorrectionReasons::all();
        $codes = array_map(static fn (OrderCorrectionReason $r): string => $r->code, $reasons);

        $expected = [
            'change_mind', 'wrong_size',
            'shortfall_disclosed', 'refused_at_door',
            'defective', 'wrong_item', 'short_pick', 'out_of_stock', 'shortfall_undisclosed',
            'pricing_error', 'cannot_ship', 'suspected_fraud',
            'damaged_in_transit', 'lost', 'undeliverable',
        ];
        sort($codes);
        sort($expected);

        $this->assertSame($expected, $codes);
    }

    public function testNoDuplicateCodes(): void
    {
        $codes = array_map(static fn (OrderCorrectionReason $r): string => $r->code, BuiltInCorrectionReasons::all());
        $this->assertSame(count($codes), count(array_unique($codes)));
    }

    public function testAllSeedRecordsHaveNullId(): void
    {
        foreach (BuiltInCorrectionReasons::all() as $reason) {
            $this->assertNull($reason->id, sprintf('Seed Record for %s should have null id', $reason->code));
        }
    }

    public function testCauseDistribution(): void
    {
        /** @var array<string, list<string>> $byCause */
        $byCause = [Cause::Customer->value => [], Cause::Merchant->value => [], Cause::Logistics->value => []];
        foreach (BuiltInCorrectionReasons::all() as $reason) {
            $byCause[$reason->cause->value][] = $reason->code;
        }

        // Sanity-check the cause assignment for representative reasons
        $this->assertContains('change_mind', $byCause[Cause::Customer->value]);
        $this->assertContains('shortfall_disclosed', $byCause[Cause::Customer->value]);
        $this->assertContains('defective', $byCause[Cause::Merchant->value]);
        $this->assertContains('shortfall_undisclosed', $byCause[Cause::Merchant->value]);
        $this->assertContains('out_of_stock', $byCause[Cause::Merchant->value]);
        $this->assertContains('lost', $byCause[Cause::Logistics->value]);
        $this->assertContains('damaged_in_transit', $byCause[Cause::Logistics->value]);
    }

    /** What can only happen to shipped units is never offered for unshipped ones, and the reverse. */
    public function testTimingFollowsWhenTheReasonCanHappen(): void
    {
        $timing = [];
        foreach (BuiltInCorrectionReasons::all() as $reason) {
            $timing[$reason->code] = $reason->timing;
        }

        $this->assertSame(CorrectionTiming::Post, $timing['wrong_size']);
        $this->assertSame(CorrectionTiming::Post, $timing['lost']);
        $this->assertSame(CorrectionTiming::Pre, $timing['cannot_ship']);
        $this->assertSame(CorrectionTiming::Pre, $timing['suspected_fraud']);
        $this->assertSame(CorrectionTiming::Any, $timing['defective']);
        $this->assertTrue(CorrectionTiming::Any->appliesTo(true) && CorrectionTiming::Any->appliesTo(false));
        $this->assertFalse(CorrectionTiming::Post->appliesTo(true), 'a post-shipment reason cannot explain a pre-dispatch type');
    }

    public function testARetiredReasonIsNeverSeeded(): void
    {
        $codes = array_map(static fn (OrderCorrectionReason $r): string => $r->code, BuiltInCorrectionReasons::all());

        foreach (BuiltInCorrectionReasons::retired() as $retired) {
            $this->assertNotContains($retired, $codes);
        }
        $this->assertContains('late_payment', BuiltInCorrectionReasons::retired());
    }

    /** The late-payment shortfalls are the system's to record; each is still a seeded reason. */
    public function testSystemOnlyReasonsAreSeeded(): void
    {
        $codes = array_map(static fn (OrderCorrectionReason $r): string => $r->code, BuiltInCorrectionReasons::all());

        $this->assertSame(['shortfall_disclosed', 'shortfall_undisclosed'], BuiltInCorrectionReasons::systemOnly());
        foreach (BuiltInCorrectionReasons::systemOnly() as $code) {
            $this->assertContains($code, $codes);
        }
    }

    /**
     * Only a reason saying the held units are unusable or not there writes them off. "Out of stock"
     * does not: the units it cancels are the ones committed stock never covered, so there is nothing
     * on the shelf to destroy, and writing some off would take them from the product's other orders.
     */
    public function testOnlyDefectiveAndMissingUnitsAreWrittenOff(): void
    {
        $writtenOff = [];
        foreach (BuiltInCorrectionReasons::all() as $reason) {
            if (BuiltInCorrectionReasons::writesOff($reason->code)) {
                $writtenOff[] = $reason->code;
            }
        }

        $this->assertSame(['defective', 'short_pick'], $writtenOff);
        $this->assertFalse(BuiltInCorrectionReasons::writesOff('out_of_stock'));
    }
}
