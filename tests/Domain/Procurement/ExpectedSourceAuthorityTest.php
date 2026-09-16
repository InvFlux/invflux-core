<?php

declare(strict_types=1);

namespace Tests\Domain\Procurement;

use Nandan108\InvFlux\Domain\Procurement\ExpectedSourceAuthority;
use Nandan108\InvFlux\Domain\Procurement\PurchaseOrderLine;
use PHPUnit\Framework\TestCase;

/**
 * `qty_expected` is one merged value with several parties wanting to write it, so this policy is
 * the whole of what keeps two of them from overwriting each other indefinitely. Its failure mode
 * has no symptom other than a figure that will not sit still — which is why it is pinned here
 * rather than left to the add-ons that consult it.
 */
final class ExpectedSourceAuthorityTest extends TestCase
{
    private static function manuallySetLine(int $expected): PurchaseOrderLine
    {
        $line = new PurchaseOrderLine();
        $line->qty_expected = $expected;
        $line->expected_source = PurchaseOrderLine::EXPECTED_SOURCE_MANUAL;

        return $line;
    }

    public function testAnUnclaimedLineIsOpenToAnyone(): void
    {
        foreach ([
            PurchaseOrderLine::EXPECTED_SOURCE_ORDERED,
            PurchaseOrderLine::EXPECTED_SOURCE_MANUAL,
            PurchaseOrderLine::EXPECTED_SOURCE_ORDER_ACK,
            PurchaseOrderLine::EXPECTED_SOURCE_SHIPMENT,
            PurchaseOrderLine::EXPECTED_SOURCE_INVOICE,
        ] as $claimant) {
            self::assertTrue(ExpectedSourceAuthority::mayWrite(null, $claimant));
        }
    }

    /**
     * The automatic ladder: later and more specific wins. A shipment notice is about goods that
     * exist; an acknowledgement is about an intention.
     */
    public function testAutomaticSourcesRankByHowLateAndSpecificTheDocumentIs(): void
    {
        self::assertTrue(ExpectedSourceAuthority::mayWrite(
            PurchaseOrderLine::EXPECTED_SOURCE_ORDER_ACK,
            PurchaseOrderLine::EXPECTED_SOURCE_SHIPMENT,
        ));
        self::assertTrue(ExpectedSourceAuthority::mayWrite(
            PurchaseOrderLine::EXPECTED_SOURCE_SHIPMENT,
            PurchaseOrderLine::EXPECTED_SOURCE_INVOICE,
        ));
        self::assertFalse(ExpectedSourceAuthority::mayWrite(
            PurchaseOrderLine::EXPECTED_SOURCE_INVOICE,
            PurchaseOrderLine::EXPECTED_SOURCE_ORDER_ACK,
        ));
    }

    /**
     * A person entering a figure has already read whatever the automatic sources said. Letting the
     * next document overwrite it discards a decision instead of refining one.
     */
    public function testNothingAutomaticOverwritesAPerson(): void
    {
        foreach ([
            PurchaseOrderLine::EXPECTED_SOURCE_ORDERED,
            PurchaseOrderLine::EXPECTED_SOURCE_ORDER_ACK,
            PurchaseOrderLine::EXPECTED_SOURCE_SHIPMENT,
            PurchaseOrderLine::EXPECTED_SOURCE_INVOICE,
        ] as $claimant) {
            self::assertFalse(ExpectedSourceAuthority::mayWrite(
                PurchaseOrderLine::EXPECTED_SOURCE_MANUAL,
                $claimant,
            ));
        }
    }

    /**
     * A second document of the same kind refines its own figure — that is the common case, and
     * treating it as a conflict would freeze every line after its first shipment notice.
     */
    public function testASourceMayRefreshItsOwnClaim(): void
    {
        self::assertTrue(ExpectedSourceAuthority::mayWrite(
            PurchaseOrderLine::EXPECTED_SOURCE_SHIPMENT,
            PurchaseOrderLine::EXPECTED_SOURCE_SHIPMENT,
        ));
    }

    /**
     * Something wrote that number meaning something by it. A holder this policy cannot place is
     * exactly where overwriting is least defensible, so an unknown value is treated as the
     * strongest claim rather than the weakest.
     */
    public function testAnUnrecognisedHolderIsNotTrampled(): void
    {
        self::assertFalse(ExpectedSourceAuthority::mayWrite(99, PurchaseOrderLine::EXPECTED_SOURCE_MANUAL));
    }

    /**
     * The one path from a strong holder back to an open slot, and it clears both halves: a claim
     * left standing over a null figure is a slot nothing can take and nothing can explain.
     */
    public function testReleasingClearsTheFigureAndTheClaimTogether(): void
    {
        $line = self::manuallySetLine(80);

        ExpectedSourceAuthority::release($line);

        self::assertNull($line->qty_expected);
        self::assertNull($line->expected_source);
        self::assertTrue(ExpectedSourceAuthority::mayWrite(
            $line->expected_source,
            PurchaseOrderLine::EXPECTED_SOURCE_SHIPMENT,
        ));
    }
}
