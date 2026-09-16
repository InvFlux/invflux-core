<?php

declare(strict_types=1);

namespace Tests\Domain\Procurement;

use Nandan108\InvFlux\Domain\Procurement\PurchaseOrderLine;
use Nandan108\InvFlux\Domain\Procurement\VarianceLens;
use Nandan108\InvFlux\Domain\Procurement\VarianceStatus;
use PHPUnit\Framework\TestCase;

/**
 * The stage-neutral variance classification ({@see VarianceStatus}) and its {@see PurchaseOrderLine}
 * specializations — the Essentials "flag short/over" signal. Pure (no DB):
 * `qty_open` is normally a DB-generated column, so each case sets it to the value the DB would compute.
 */
final class VarianceStatusTest extends TestCase
{
    // ── The pure classifier ──────────────────────────────────────────────────────────────────────

    public function testClassifyOverWhenActualExceedsBaseline(): void
    {
        self::assertSame(VarianceStatus::Over, VarianceStatus::classify(baseline: 10, actual: 12, finalized: false));
        self::assertSame(VarianceStatus::Over, VarianceStatus::classify(baseline: 10, actual: 12, finalized: true));
    }

    public function testClassifyMatchWhenEqual(): void
    {
        self::assertSame(VarianceStatus::Match, VarianceStatus::classify(baseline: 10, actual: 10, finalized: false));
    }

    public function testClassifyUnderIsOpenWhileUnfinalizedShortOnceFinalized(): void
    {
        self::assertSame(VarianceStatus::Open, VarianceStatus::classify(baseline: 10, actual: 4, finalized: false));
        self::assertSame(VarianceStatus::Short, VarianceStatus::classify(baseline: 10, actual: 4, finalized: true));
    }

    public function testIsDiscrepancyOnlyForOverAndShort(): void
    {
        self::assertTrue(VarianceStatus::Over->isDiscrepancy());
        self::assertTrue(VarianceStatus::Short->isDiscrepancy());
        self::assertFalse(VarianceStatus::Open->isDiscrepancy());
        self::assertFalse(VarianceStatus::Match->isDiscrepancy());
    }

    // ── Delivery variance (reception) ────────────────────────────────────────────────────────────

    public function testDeliveryExactWhenReceivedEqualsBaselineAndNothingOpen(): void
    {
        $line = $this->line(requested: 10, received: 10, open: 0);
        self::assertSame(0, $line->deliveryVarianceQty());
        self::assertSame(VarianceStatus::Match, $line->deliveryStatus());
    }

    public function testDeliveryOpenWhenPartiallyReceivedAndMoreExpected(): void
    {
        // Received 4 of 10 ordered, 6 still open — in progress, not a discrepancy.
        $line = $this->line(requested: 10, received: 4, open: 6);
        self::assertSame(-6, $line->deliveryVarianceQty());
        self::assertSame(VarianceStatus::Open, $line->deliveryStatus());
        self::assertFalse($line->deliveryStatus()->isDiscrepancy());
    }

    public function testDeliveryOverWhenReceivedExceedsBaseline(): void
    {
        $line = $this->line(requested: 10, received: 12, open: 0);
        self::assertSame(2, $line->deliveryVarianceQty());
        self::assertSame(VarianceStatus::Over, $line->deliveryStatus());
    }

    public function testDeliveryShortWhenFinalizedBelowBaselineViaCloseShort(): void
    {
        // Received 7 of 10, remaining 3 written off short → finalized short delivery.
        $line = $this->line(requested: 10, received: 7, open: 0, closedShort: 3);
        self::assertSame(VarianceStatus::Short, $line->deliveryStatus());
    }

    public function testDeliveryLensSwitchesTheBaseline(): void
    {
        // Ordered 10, supplier confirmed 8, received 9: over vs the ops (expected=8) baseline,
        // but short-of-ordered vs the admin (ordered=10) baseline once finalized.
        $line = $this->line(requested: 10, received: 9, open: 0);
        $line->qty_expected = 8;

        self::assertSame(1, $line->deliveryVarianceQty(VarianceLens::Expected), 'received 9 vs confirmed 8');
        self::assertSame(VarianceStatus::Over, $line->deliveryStatus(VarianceLens::Expected));

        self::assertSame(-1, $line->deliveryVarianceQty(VarianceLens::Ordered), 'received 9 vs ordered 10');
        self::assertSame(VarianceStatus::Short, $line->deliveryStatus(VarianceLens::Ordered));
    }

    public function testDeliveryDefaultsToExpectedLens(): void
    {
        $line = $this->line(requested: 10, received: 10, open: 0);
        $line->qty_expected = 8;
        self::assertSame($line->deliveryStatus(VarianceLens::Expected), $line->deliveryStatus(), 'default lens is Expected');
        self::assertSame(VarianceStatus::Over, $line->deliveryStatus(), 'received 10 vs confirmed 8 → over');
    }

    // ── Confirmation variance (submit / in-transit) ──────────────────────────────────────────────

    public function testConfirmationMatchWhenNoOaRecorded(): void
    {
        $line = $this->line(requested: 10, received: 0, open: 10);
        self::assertNull($line->qty_expected);
        self::assertSame(0, $line->confirmationVarianceQty());
        self::assertSame(VarianceStatus::Match, $line->confirmationStatus(), 'expected falls back to ordered');
    }

    public function testConfirmationShortWhenSupplierConfirmsBelowOrdered(): void
    {
        // Ordered 10, supplier confirmed 8 — a confirmed shortfall, finalized (never Open).
        $line = $this->line(requested: 10, received: 0, open: 8);
        $line->qty_expected = 8;
        self::assertSame(-2, $line->confirmationVarianceQty());
        self::assertSame(VarianceStatus::Short, $line->confirmationStatus());
    }

    public function testConfirmationOverWhenSupplierConfirmsAboveOrdered(): void
    {
        $line = $this->line(requested: 10, received: 0, open: 12);
        $line->qty_expected = 12;
        self::assertSame(2, $line->confirmationVarianceQty());
        self::assertSame(VarianceStatus::Over, $line->confirmationStatus());
    }

    // ── Baselines ────────────────────────────────────────────────────────────────────────────────

    public function testExpectedQtyPrefersConfirmedOverRequested(): void
    {
        $line = $this->line(requested: 10, received: 0, open: 8);
        $line->qty_expected = 8;
        self::assertSame(8, $line->expectedQty());
        self::assertSame(8, $line->baselineQty(VarianceLens::Expected));
        self::assertSame(10, $line->baselineQty(VarianceLens::Ordered));
    }

    public function testExpectedQtyFallsBackToRequestedWhenNotConfirmed(): void
    {
        $line = $this->line(requested: 10, received: 5, open: 5);
        self::assertNull($line->qty_expected);
        self::assertSame(10, $line->expectedQty());
    }

    private function line(int $requested, int $received, int $open, int $closedShort = 0): PurchaseOrderLine
    {
        $line = new PurchaseOrderLine();
        $line->qty_requested = $requested;
        $line->qty_received = $received;
        $line->qty_open = $open; // DB-generated in reality; set explicitly for the unit test
        $line->qty_closed_short = $closedShort;

        return $line;
    }
}
