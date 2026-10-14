<?php

declare(strict_types=1);

namespace Tests\Domain\Procurement;

use Nandan108\InvFlux\Domain\Procurement\InvalidPurchaseOrderTransition;
use Nandan108\InvFlux\Domain\Procurement\PoStatus;
use Nandan108\InvFlux\Domain\Procurement\PurchaseOrderLifecycle;
use PHPUnit\Framework\TestCase;

final class PurchaseOrderLifecycleTest extends TestCase
{
    private function free(): PurchaseOrderLifecycle
    {
        return new PurchaseOrderLifecycle();
    }

    public function testForwardEdgesAreLegal(): void
    {
        $l = $this->free();
        self::assertTrue($l->canTransition(PoStatus::InPrep, PoStatus::Submitted));
        self::assertTrue($l->canTransition(PoStatus::Submitted, PoStatus::InTransit));
        self::assertTrue($l->canTransition(PoStatus::InTransit, PoStatus::InReception));
        self::assertTrue($l->canTransition(PoStatus::InReception, PoStatus::Received));
    }

    /** The supplier-OA waypoint is optional: reachable from submitted, and skippable straight to in_transit. */
    public function testAcknowledgedIsAnOptionalWaypoint(): void
    {
        $l = $this->free();
        self::assertTrue($l->canTransition(PoStatus::Submitted, PoStatus::Acknowledged));
        self::assertTrue($l->canTransition(PoStatus::Acknowledged, PoStatus::InTransit));
        // Skippable — an install that doesn't track acknowledgements goes straight on.
        self::assertTrue($l->canTransition(PoStatus::Submitted, PoStatus::InTransit));
        self::assertTrue($l->canTransition(PoStatus::Acknowledged, PoStatus::Canceled));
        self::assertSame('po.acknowledged', $l->eventTypeFor(PoStatus::Acknowledged));
    }

    /**
     * The review states are schema-present but carry no base edges — the review add-on contributes
     * them at runtime. Their landing event names are reserved here so it rarely needs registerEventType().
     */
    /**
     * Arrival is an optional waypoint between transit and counting: recorded at the dock, then
     * counted. A split shipment's next delivery can be recorded at the dock too. Nothing is counted
     * on arrival, so counting is its only exit.
     */
    public function testArrivedIsAnOptionalWaypointBeforeCounting(): void
    {
        $l = $this->free();
        self::assertTrue($l->canTransition(PoStatus::InTransit, PoStatus::Arrived));
        self::assertTrue($l->canTransition(PoStatus::Arrived, PoStatus::InReception));
        self::assertTrue($l->canTransition(PoStatus::PartiallyReceived, PoStatus::Arrived));
        // Skippable — a shop that counts as it unloads goes straight to reception.
        self::assertTrue($l->canTransition(PoStatus::InTransit, PoStatus::InReception));
        // Unannounced deliveries can be recorded at the dock straight from the order's open states.
        self::assertTrue($l->canTransition(PoStatus::Submitted, PoStatus::Arrived));
        self::assertTrue($l->canTransition(PoStatus::Acknowledged, PoStatus::Arrived));
        // An abandoned reception returns to the dock; a delivery refused there is canceled.
        self::assertTrue($l->canTransition(PoStatus::InReception, PoStatus::Arrived));
        self::assertTrue($l->canTransition(PoStatus::Arrived, PoStatus::Canceled));
        // Refused at the dock with a replacement coming: the order goes back to waiting for it.
        self::assertTrue($l->canTransition(PoStatus::Arrived, PoStatus::InTransit));
        // The same refusal on an order whose earlier deliveries came in rests as partially received.
        self::assertTrue($l->canTransition(PoStatus::Arrived, PoStatus::PartiallyReceived));
        self::assertFalse($l->canTransition(PoStatus::InPrep, PoStatus::Arrived));
        self::assertFalse($l->canTransition(PoStatus::Arrived, PoStatus::Received));
        self::assertSame('po.arrived', $l->eventTypeFor(PoStatus::Arrived));
    }

    /**
     * Goods can arrive before anyone recorded that they shipped, so reception is reachable without
     * passing through in-transit.
     *
     * The edge exists so an unannounced delivery is recorded as an arrival rather than forcing a
     * shipment event nobody observed. Pinned because the lifecycle table is permissive by
     * construction — a removal here would surface as a floor worker unable to count a pallet that is
     * physically in front of them, which is a long way from this file.
     */
    public function testReceptionIsReachableWithoutAShipmentHavingBeenRecorded(): void
    {
        $l = $this->free();
        self::assertTrue($l->canTransition(PoStatus::Submitted, PoStatus::InReception));
        self::assertTrue($l->canTransition(PoStatus::Acknowledged, PoStatus::InReception));
        // Still forward-only: an order nobody has sent has nothing to receive.
        self::assertFalse($l->canTransition(PoStatus::InPrep, PoStatus::InReception));
        self::assertFalse($l->canTransition(PoStatus::Approved, PoStatus::InReception));
    }

    public function testReviewStatesAreInertOnABaseInstall(): void
    {
        $l = $this->free();
        self::assertFalse($l->canTransition(PoStatus::InPrep, PoStatus::InReview));
        self::assertFalse($l->canTransition(PoStatus::InReview, PoStatus::Approved));
        self::assertFalse($l->canTransition(PoStatus::Approved, PoStatus::Submitted));
        self::assertSame([], $l->transitionsFrom(PoStatus::InReview));
        self::assertSame([], $l->transitionsFrom(PoStatus::Approved));
        // Reserved names, ready for the add-on's contributed edges.
        self::assertSame('po.approved', $l->eventTypeFor(PoStatus::Approved));
        self::assertSame('po.submitted_for_review', $l->eventTypeFor(PoStatus::InReview));
    }

    public function testCancelAndArchiveEdges(): void
    {
        $l = $this->free();
        // Cancellable while non-received…
        self::assertTrue($l->canTransition(PoStatus::InPrep, PoStatus::Canceled));
        self::assertTrue($l->canTransition(PoStatus::InReception, PoStatus::Canceled));
        // …but not once received.
        self::assertFalse($l->canTransition(PoStatus::Received, PoStatus::Canceled));
    }

    /**
     * Filing an order away is not a status, so the lifecycle knows nothing about it and a canceled
     * order is a dead end. A stray edge there would be the model regressing: it would mean an order
     * could be put out of sight only by overwriting how it turned out.
     */
    public function testCanceledIsTerminal(): void
    {
        self::assertSame([], $this->free()->transitionsFrom(PoStatus::Canceled));
    }

    /**
     * A received order goes back only to receiving — partially received, or in transit when a
     * correction took everything back — for units a corrected receipt left open. Never to a draft,
     * never canceled.
     */
    public function testAReceivedOrderReopensOnlyForReceiving(): void
    {
        $l = $this->free();
        self::assertSame([PoStatus::PartiallyReceived, PoStatus::InTransit], $l->transitionsFrom(PoStatus::Received));
        self::assertFalse($l->canTransition(PoStatus::Received, PoStatus::InReception));
        self::assertFalse($l->canTransition(PoStatus::Received, PoStatus::InPrep));
    }

    public function testIllegalEdgesAreRejected(): void
    {
        $l = $this->free();
        // No skipping the chain, no going backwards.
        self::assertFalse($l->canTransition(PoStatus::InPrep, PoStatus::InTransit));
        self::assertFalse($l->canTransition(PoStatus::Submitted, PoStatus::InPrep));
    }

    public function testAssertThrowsOnIllegalEdge(): void
    {
        $this->expectException(InvalidPurchaseOrderTransition::class);
        $this->free()->assertCanTransition(PoStatus::Received, PoStatus::InPrep);
    }

    public function testEventTypeForTargetStatus(): void
    {
        $l = $this->free();
        self::assertSame('po.submitted', $l->eventTypeFor(PoStatus::Submitted));
        self::assertSame('po.received', $l->eventTypeFor(PoStatus::Received));
        self::assertSame('po.canceled', $l->eventTypeFor(PoStatus::Canceled));
    }

    public function testEventTypeForUnknownStatusThrows(): void
    {
        $this->expectException(InvalidPurchaseOrderTransition::class);
        $this->free()->eventTypeFor(PoStatus::InPrep); // no event for re-entering In Prep
    }

    public function testCancelUnproductiveReceptionEdge(): void
    {
        $l = $this->free();
        // A reception opened by mistake / with nothing counted steps back to in_transit — a pure status
        // undo (no goods moved), distinct from cancellation.
        self::assertTrue($l->canTransition(PoStatus::InReception, PoStatus::InTransit));
        // Still not a general backward door: reception does not fall back to submitted or in_prep.
        self::assertFalse($l->canTransition(PoStatus::InReception, PoStatus::Submitted));
        self::assertFalse($l->canTransition(PoStatus::InReception, PoStatus::InPrep));
    }

    /**
     * "Keep a PO open across deliveries" is base behavior — receiving one delivery of a split shipment
     * and parking the PO until the next is recording what physically arrived. The *entry* edge
     * in_reception → partially_received is seeded here, so a base install reaches the state on its own.
     */
    public function testBaseLifecycleOpensAPoAcrossDeliveries(): void
    {
        $l = $this->free();
        self::assertTrue($l->canTransition(PoStatus::InReception, PoStatus::PartiallyReceived), 'a base PO can be kept open across deliveries');
        self::assertContains(PoStatus::PartiallyReceived, $l->transitionsFrom(PoStatus::InReception));
        self::assertSame('po.partially_received', $l->eventTypeFor(PoStatus::PartiallyReceived));
    }

    /**
     * The exit edges out of partially_received: record the next delivery at the dock, reopen to receive
     * it, or finalize
     * (a final receipt or a close-short both land on received). Not cancellable once partially received
     * (goods are already in).
     */
    public function testLifecycleWindsDownAPartial(): void
    {
        $l = $this->free();
        self::assertTrue($l->canTransition(PoStatus::PartiallyReceived, PoStatus::InReception), 'must be able to reopen for the next delivery');
        self::assertTrue($l->canTransition(PoStatus::PartiallyReceived, PoStatus::Received), 'must be able to finalize / close short');
        self::assertFalse($l->canTransition(PoStatus::PartiallyReceived, PoStatus::Canceled));
        self::assertSame([PoStatus::Arrived, PoStatus::InReception, PoStatus::Received], $l->transitionsFrom(PoStatus::PartiallyReceived));
    }

    /**
     * The registration mechanism a future inbound-shipment (advance-notice) add-on relies on:
     * registerTransition appends a genuinely new edge and is idempotent. (The partial-receipt edges are
     * all base now; this proves the seam still works for edges layered on top.)
     */
    public function testRegisterTransitionAppendsAndIsIdempotent(): void
    {
        $l = $this->free();
        // A hypothetical add-on edge (received → in_reception, i.e. count a delivery straight against a
        // finalized PO, skipping the base reopen step).
        $l->registerTransition(PoStatus::Received, PoStatus::InReception);
        $l->registerTransition(PoStatus::Received, PoStatus::InReception);

        self::assertTrue($l->canTransition(PoStatus::Received, PoStatus::InReception));
        // Existing edges from received survive the contribution (append, not replace); no duplicate.
        self::assertSame(
            [PoStatus::PartiallyReceived, PoStatus::InTransit, PoStatus::InReception],
            $l->transitionsFrom(PoStatus::Received),
        );
    }

    public function testRegisterEventTypeContributesLandingEvent(): void
    {
        $l = $this->free();
        $l->registerEventType(PoStatus::InPrep, 'po.reopened');
        self::assertSame('po.reopened', $l->eventTypeFor(PoStatus::InPrep));
    }
}
